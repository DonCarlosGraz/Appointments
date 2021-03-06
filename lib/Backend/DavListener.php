<?php /** @noinspection PhpFullyQualifiedNameUsageInspection */


namespace OCA\Appointments\Backend;


use OCA\Appointments\AppInfo\Application;
use OCP\AppFramework\QueryException;
use Sabre\VObject\Reader;
use Symfony\Component\EventDispatcher\GenericEvent;

class DavListener {

    const DEL_EVT_NAME='\OCA\DAV\CalDAV\CalDavBackend::deleteCalendarObject';

    private $appName;
    private $l10N;
    public function __construct(){
        $this->appName=Application::APP_ID;
        $this->l10N=\OC::$server->getL10N($this->appName);
    }

    /**
     * @param GenericEvent $event
     * @param string $eventName
     */
    public function handle(GenericEvent $event, $eventName): void{

        if(!isset($event['objectData']['calendardata'])){
            return;
        }
        $cd=$event['objectData']['calendardata'];

        if(strpos($cd,"\r\nATTENDEE;")===false
            || strpos($cd,"\r\nCATEGORIES:".BackendUtils::APPT_CAT."\r\n")===false
            || strpos($cd,"\r\n".BackendUtils::TZI_PROP.":")===false
            || strpos($cd,"\r\nORGANIZER;")===false
            || strpos($cd,"\r\nUID:")===false){
                // Not a good appointment, bail early...
            return;
        }

        $ses=\OC::$server->getSession();
        $hint=$ses->get(BackendUtils::APPT_SES_KEY_HINT);
        if($hint===BackendUtils::APPT_SES_SKIP
            || ($eventName===self::DEL_EVT_NAME && $hint===BackendUtils::APPT_SES_CONFIRM) // <-- booking in to a different calendar NOT deleting
        ){
            // no need for email
            return;
        }

        $vObject=Reader::read($cd);
        if(!isset($vObject->VEVENT)){
            // Not a VEVENT
            return;
        }
        /** @var \Sabre\VObject\Component\VEvent $evt*/
        $evt=$vObject->VEVENT;
        if(!isset($evt->UID)){
            \OC::$server->getLogger()->error('UID not found');
            return;
        }

        try {
            /** @var BackendUtils $utils*/
            $utils = \OC::$server->query(BackendUtils::class);
        } catch (QueryException $e) {
            \OC::$server->getLogger()->error($e->getMessage());
            return;
        }

        $config=\OC::$server->getConfig();

        if(isset($evt->{BackendUtils::XAD_PROP})){
            // @see BackendUtils->dataSetAttendee for BackendUtils::XAD_PROP
            $xad=explode(chr(31),$utils->decrypt(
                $evt->{BackendUtils::XAD_PROP}->getValue(),
                $evt->UID->getValue()));
            $userId=$xad[0];
        }else {
            \OC::$server->getLogger()->error("XAD_PROP not found");
            return;
        }

        $other_cal='-1';
        $cal_id=$utils->getMainCalId($userId,null,$other_cal);

        if($other_cal!=='-1'){
            // only allowed in simple
            if($utils->getUserSettings(
                BackendUtils::KEY_CLS,$userId)[BackendUtils::CLS_TS_MODE]!=='0'){
                $other_cal='-1';
            }
        }

        // Check cal IDs.
        // $event['calendarData']['id'] can be a string or an int
        if($cal_id!=$event['calendarData']['id'] && $other_cal!=$event['calendarData']['id']){
            // Not this user's calendar
            return;
        }

        $hash=$utils->getApptHash($evt->UID->getValue());
        if($eventName===self::DEL_EVT_NAME){
            $utils->deleteApptHash($evt);
        }

        if($hash===null
            || !isset($evt->ATTENDEE)
            || !isset($evt->STATUS)
            || !isset($evt->DTEND)
            || !isset($evt->ORGANIZER)
        ){
            // Bad data
            return;
        }

        $utz=$utils->getUserTimezone($userId,$config);
        try {
            $now = new \DateTime('now', $utz);
        } catch (\Exception $e) {
            \OC::$server->getLogger()->error($e->getMessage().", timezone: ".$utz->getName());
            return;
        }

        // TODO: this needs to be fixed @see BackendUtils->encodeCalendarData
        $now_f=(float)$now->format(BackendUtils::FLOAT_TIME_FORMAT);
        if($now_f > (float)str_replace("T",".",$evt->DTEND->getRawMimeDirValue())
            && $now_f > $utils->getHashDTStart($hash)
        ){
            // Event is in the past
            return;
        }

        $hash_ch=$utils->getHashChanges($hash,$evt);

        $eml_settings=$utils->getUserSettings(
            BackendUtils::KEY_EML,$userId);

        $att=$utils->getAttendee($evt);
        if($att===null
            || ($hint===null
                && ($att->parameters['PARTSTAT']->getValue()==='DECLINED'
                    || ($hash_ch===null && $eventName!==self::DEL_EVT_NAME)
                    || $utils->isApptCancelled($hash,$evt)===true
                    || ($eml_settings[BackendUtils::EML_ADEL]===false
                        && $eventName===self::DEL_EVT_NAME)
                    || ($eml_settings[BackendUtils::EML_AMOD]===false
                        && $eventName!==self::DEL_EVT_NAME))
            )){
            // Bad attendee value or no significant external changes or cancelled or emails not wanted
            return;
        }

        $to_name=$att->parameters['CN']->getValue();
        if(empty($to_name) || preg_match('/[^\PC ]/u',$to_name)){
            \OC::$server->getLogger()->error("invalid attendee name");
            return;
        }

        $mailer=\OC::$server->getMailer();

        $att_v=$att->getValue();
        $to_email=substr($att_v,strpos($att_v,":")+1);
        if($mailer->validateMailAddress($to_email)===false){
            \OC::$server->getLogger()->error("invalid attendee email");
            return;
        }

        $date_time=$utils->getDateTimeString(
            $evt->DTSTART->getDateTime(),
            $evt->{BackendUtils::TZI_PROP}->getValue()
        );

        $org=$utils->getUserSettings(
            BackendUtils::KEY_ORG,$userId);

        $org_name=$org[BackendUtils::ORG_NAME];
        $org_email=$org[BackendUtils::ORG_EMAIL];
        $org_phone=$org[BackendUtils::ORG_PHONE];

        $is_cancelled=false;

        $tmpl=$mailer->createEMailTemplate("ID_".time());

        // Message the organizer
        $om_prefix="";
        // Description can get overwritten when the .ics attachment is constructed, so get it here
        if(isset($evt->DESCRIPTION)){
            $om_info=$evt->DESCRIPTION->getValue();
        }

        $cnl_lnk_url=""; // cancellation link for confirmation emails

        if($hint === BackendUtils::APPT_SES_BOOK){
            // Just booked, send email to the attendee requesting confirmation...

            // TRANSLATORS Subject for email, Ex: {{Organization Name}} Appointment (action needed)
            $tmpl->setSubject($this->l10N->t("%s appointment (action needed)",[$org_name]));
            // TRANSLATORS First line of email, Ex: Dear {{Customer Name}},
            $tmpl->addBodyText($this->l10N->t("Dear %s,",$to_name));

            // TRANSLATORS Main part of email, Ex: The {{Organization Name}} appointment scheduled for {{Date Time}} is awaiting your confirmation.
            $tmpl->addBodyText($this->l10N->t('The %1$s appointment scheduled for %2$s is awaiting your confirmation.',[$org_name,$date_time]));

            // These keys must be set in the page controller
            $btn_url=$ses->get(BackendUtils::APPT_SES_KEY_BURL);
            $btn_tkn=$ses->get(BackendUtils::APPT_SES_KEY_BTKN);

            $tmpl->addBodyButtonGroup(
                $this->l10N->t("Confirm"),
                $btn_url.'1'.$btn_tkn,
                $this->l10N->t("Cancel"),
                $btn_url.'0'.$btn_tkn
            );

            if(!empty($eml_settings[BackendUtils::EML_VLD_TXT])){
                $tmpl->addBodyText($eml_settings[BackendUtils::EML_VLD_TXT]);
            }

            if($eml_settings[BackendUtils::EML_MREQ]){
                $om_prefix=$this->l10N->t("Appointment pending");
            }

        }elseif ($hint === BackendUtils::APPT_SES_CONFIRM){
            // Confirm link in the email is clicked ...
            // ... or the email validation step is skipped

            // TRANSLATORS Subject for email, Ex: {{Organization Name}} Appointment is Confirmed
            $tmpl->setSubject($this->l10N->t("%s Appointment is confirmed",[$org_name]));
            $tmpl->addBodyText($to_name.",");
            // TRANSLATORS Main body of email,Ex: Your {{Organization Name}} appointment scheduled for {{Date Time}} is now confirmed.
            $tmpl->addBodyText($this->l10N->t('Your %1$s appointment scheduled for %2$s is now confirmed.',[$org_name,$date_time]));

            if(!empty($eml_settings[BackendUtils::EML_CNF_TXT])){
                $tmpl->addBodyText($eml_settings[BackendUtils::EML_CNF_TXT]);
            }

            // add cancellation link
            // These keys must be set in the page controller
            $cnl_lnk_url=$ses->get(BackendUtils::APPT_SES_KEY_BURL)."0".$ses->get(BackendUtils::APPT_SES_KEY_BTKN);

            if($eml_settings[BackendUtils::EML_MCONF]) {
                $om_prefix = $this->l10N->t("Appointment confirmed");
            }

        }elseif ($hint === BackendUtils::APPT_SES_CANCEL || $eventName===self::DEL_EVT_NAME){
            // Canceled or deleted

            if($hint!==null) {
                // Cancelled by the attendee (via the email link)

                // TRANSLATORS Subject for email, Ex: {{Organization Name}} Appointment is Canceled
                $tmpl->setSubject($this->l10N->t("%s Appointment is canceled", [$org_name]));
            }else{
                // Cancelled/deleted by the organizer

                // TRANSLATORS Subject for email, Ex: {{Organization Name}} Appointment Status Changed
                $tmpl->setSubject($this->l10N->t("%s appointment status changed", [$org_name]));
            }

            // TRANSLATORS Main body of email,Ex: Your {{Organization Name}} appointment scheduled for {{Date Time}} is now canceled.
            $tmpl->addBodyText($to_name.",");
            $tmpl->addBodyText($this->l10N->t('Your %1$s appointment scheduled for %2$s is now canceled.',[$org_name,$date_time]));
            $is_cancelled=true;

            if($eml_settings[BackendUtils::EML_MCNCL] && $hint!==null) {
                $om_prefix = $this->l10N->t("Appointment canceled");
            }

        }elseif($hint === null){
            // Organizer or External Action (something changed...)

            // TRANSLATORS Subject for email, Ex: {{Organization Name}} appointment status update
            $tmpl->setSubject($this->l10N->t("%s Appointment update",[$org_name]));
            // TRANSLATORS First line of email, Ex: Dear {{Customer Name}},
            $tmpl->addBodyText($this->l10N->t("Dear %s,",[$to_name]));
            // TRANSLATORS Main part of email
            $tmpl->addBodyText($this->l10N->t("Your appointment details have changed. Please review information below."));

            // Add changes details...
            if($hash_ch[0]===true) { // DTSTART changed
                $tmpl->addBodyListItem($this->l10N->t("Date/Time: %s", [$date_time]));
            }

            if($hash_ch[1]===true) { //STATUS changed
                if ($evt->STATUS->getValue() === 'CANCELLED') {
                    $tmpl->addBodyListItem($this->l10N->t('Status: Canceled'));
                    $is_cancelled = true;
                } else {
                    // Non cancelled status is determined by the attendee's PARTSTAT
                    $pst = $att->parameters['PARTSTAT']->getValue();
                    if ($pst === 'NEEDS-ACTION') {
                        $tmpl->addBodyListItem($this->l10N->t('Status: Pending confirmation'));
                    } elseif ($pst === 'ACCEPTED') {
                        $tmpl->addBodyListItem($this->l10N->t('Status: Confirmed'));
                    }
                }
            }

            if($hash_ch[2]===true && isset($evt->LOCATION)){ // LOCATION changed
                $tmpl->addBodyListItem($this->l10N->t("Location: %s", [$evt->LOCATION->getValue()]));
            }

            if(empty($org_phone)) {
                // TRANSLATORS Additional part of email - contact information WITHOUT phone number (only email). The last argument is email address.
                $tmpl->addBodyText($this->l10N->t("If you have any questions please write to %s", [$org_email]));
            }else{
                // TRANSLATORS Additional part of email - contact information WITH email AND phone number: If you have any questions please feel free to call {123-456-7890} or write to {email@example.com}
                $tmpl->addBodyText($this->l10N->t('If you have any questions please feel free to call %1$s or write to %2$s', [$org_phone,$org_email]));
            }

            // Update hash
            $utils->setApptHash($evt);

        }else return;


        $tmpl->addBodyText($this->l10N->t("Thank you"));

        // cancellation link for confirmation emails
        if(!empty($cnl_lnk_url)){
            $tmpl->addBodyText(
                '<div style="font-size: 80%;color: #989898">' .
                // TRANSLATORS This is a part of an email message. %1$s Cancel Appointment %2$s is a link to the cancellation page (HTML format).
                $this->l10N->t('To cancel your appointment please click: %1$s Cancel Appointment %2$s', ['<a style="color: #989898" href="' . $cnl_lnk_url . '">', '</a>'])
                . "</div>",
                // TRANSLATORS This is a part of an email message. %s is a URL of the cancellation page (PLAIN TEXT format).
                $this->l10N->t('To cancel your appointment please visit: %s', $cnl_lnk_url)
            );
        }

        $tmpl->addFooter("Booked via Nextcloud Appointments App");

        ///-------------------

        $def_email=\OCP\Util::getDefaultEmailAddress('appointments-noreply');

        $msg=$mailer->createMessage();

        if($config->getAppValue($this->appName,
                BackendUtils::KEY_USE_DEF_EMAIL,
                'yes')==='no')
        {
            $msg->setFrom(array($org_email));
        }else{
            $msg->setFrom(array($def_email));
            $msg->setReplyTo(array($org_email));
        }
        $msg->setTo(array($to_email));
        $msg->useTemplate($tmpl);

        $utz_info=$evt->{BackendUtils::TZI_PROP}->getValue()[0];

        // .ics attachment
        if($hint!== BackendUtils::APPT_SES_BOOK && $eml_settings[BackendUtils::EML_ICS]===true){

            // method https://tools.ietf.org/html/rfc5546#section-3.2
            if(!$is_cancelled){
                $method='PUBLISH';

                if(!empty($org_phone)){
                    if (!isset($evt->DESCRIPTION)) $evt->add('DESCRIPTION');
                    $evt->DESCRIPTION->setValue($org_name."\n".$org_phone);
                }else {
                    if (isset($evt->DESCRIPTION)) {
                        $evt->remove($evt->DESCRIPTION);
                    }
                }
            }else{
                $method='CANCEL';
                if($eventName===self::DEL_EVT_NAME){
                    // Set proper info, otherwise the .ics file is bad
                    if($hint===null){
                        // Organizer deleted the appointment
                        $evt->STATUS->setValue('CANCELLED');
                    }else {
                        $utils->evtCancelAttendee($evt);
                    }
                    $utils->setSEQ($evt);
                }

                if(isset($evt->DESCRIPTION)){
                    $evt->DESCRIPTION->setValue(
                        $this->l10N->t("Appointment is canceled")
                    );
                }
            }

            // SCHEDULE-AGENT
            // https://tools.ietf.org/html/rfc6638#section-7.1
            // Servers MUST NOT include this parameter in any scheduling messages sent as the result of a scheduling operation.
            // Clients MUST NOT include this parameter in any scheduling messages that they themselves send.

//            if (isset($att->parameters['SCHEDULE-AGENT'])) {
//                unset($att->parameters['SCHEDULE-AGENT']);
//            }

//            if(isset($evt->ORGANIZER->parameters['SCHEDULE-AGENT'])){
//                unset($evt->ORGANIZER->parameters['SCHEDULE-AGENT']);
//            }

            if(!isset($vObject->METHOD)) $vObject->add('METHOD');
            $vObject->METHOD->setValue($method);

            if(!isset($evt->SUMMARY)) $evt->add('SUMMARY');
            $evt->SUMMARY->setValue($this->l10N->t("%s Appointment",[$org_name]));

            if(isset($evt->{BackendUtils::TZI_PROP})){
                $evt->remove($evt->{BackendUtils::TZI_PROP});
            }
            if(isset($evt->{BackendUtils::XAD_PROP})){
                $evt->remove($evt->{BackendUtils::XAD_PROP});
            }

            $msg->attach(
                $mailer->createAttachment(
                    $vObject->serialize(),
                    'appointment.ics',
                    'text/calendar; method='.$method
                )
            );

        }

        try {
            $mailer->send($msg);
        } catch (\Exception $e) {
            \OC::$server->getLogger()->error("Can not send email to ".$to_email);
            \OC::$server->getLogger()->error($e->getMessage());
            return;
        }

        // Email the Organizer
        if(!empty($om_prefix) && isset($om_info)) {
            // $om_info should have attendee info separated by \n
            $oma=explode("\n",$om_info);
            // At least two parts (name and email, [phone optional])
            $omc=count($oma);
            if($omc>1 && $omc<5) {

                $evt_dt=$evt->DTSTART->getDateTime();
                // Here we need organizer's timezone for getDateTimeString()
                $utz_info.=$utils->getUserTimezone($userId,$config)->getName();

                $tmpl = $mailer->createEMailTemplate("ID_" . time());
                $tmpl->setSubject($om_prefix . ": " . $to_name . ", "
                    . $utils->getDateTimeString($evt_dt,$utz_info,true));
                $tmpl->addHeading(" "); // spacer
                $tmpl->addBodyText($om_prefix);
                $tmpl->addBodyListItem($utils->getDateTimeString($evt_dt,$utz_info));
                foreach ($oma as $info){
                    $tmpl->addBodyListItem($info);
                }

                $msg=$mailer->createMessage();
                $msg->setFrom(array($def_email));
                $msg->setTo(array($org_email));
                $msg->useTemplate($tmpl);

                try {
                    $mailer->send($msg);
                } catch (\Exception $e) {
                    \OC::$server->getLogger()->error("Can not send email to ".$org_email);
                    return;
                }
            }else{
                \OC::$server->getLogger()->error("Bad oma count");
            }
        }
    }
}