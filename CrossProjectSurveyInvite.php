<?php

namespace Vanderbilt\CrossProjectSurveyInvite;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;

class CrossProjectSurveyInvite extends AbstractExternalModule
{
    function redcap_data_entry_form($project_id, $record, $instrument, $event_id, $group_id = NULL, $repeat_instance = 1) {

    }

    function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id = NULL, $survey_hash = NULL, $response_id = NULL, $repeat_instance = 1) {
        $emailFields = $this->getProjectSetting('email_field');
        $destinationProjects = $this->getProjectSetting('destination_project');
        $surveyForms = $this->getProjectSetting('survey_form');
        $emailLanguages = $this->getProjectSetting('email_language');
        $emailSenders = $this->getProjectSetting('email_sender');
        $emailSubjects = $this->getProjectSetting('email_subject');
        $sendDates = $this->getProjectSetting('send_date_field');

        $currentProject = new \Project($project_id);
        $currentMetaData = $currentProject->metadata;

        // Set content-type header for sending HTML email
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";

        foreach ($destinationProjects as $index => $destinationProject) {
            $emailField = $emailFields[$index];
            $surveyForm = $surveyForms[$index];
            $senderField = $emailSenders[$index];
            $languageField = $emailLanguages[$index];
            $subjectField = $emailSubjects[$index];
            $sendDateField = $sendDates[$index];

            $projectObject = new \Project($destinationProject);
            $surveyId = $projectObject->forms[$surveyForm]['survey_id'];

            $languageMetaData = $currentMetaData[$languageField];

            $fieldList = array($emailField,$senderField,$languageField,$subjectField,$sendDateField);

            $currentData = \REDCap::getData($project_id, 'array', array($record), $fieldList);

            $emailValue = $this->getFieldValue($currentData,$record,$event_id,$currentMetaData[$emailField]['form_name'],$emailField,$repeat_instance);
            $senderValue = $this->getFieldValue($currentData,$record,$event_id,$currentMetaData[$senderField]['form_name'],$senderField,$repeat_instance);
            $subjectValue = $this->getFieldValue($currentData,$record,$event_id,$currentMetaData[$subjectField]['form_name'],$subjectField,$repeat_instance);
            $sendDateValue = $this->getFieldValue($currentData,$record,$event_id,$currentMetaData[$sendDateField]['form_name'],$sendDateField,$repeat_instance);

            if ($languageMetaData['element_type'] == 'descriptive') {
                $emailLanguage = $languageMetaData['element_label'];
            }
            else {
                $emailLanguage = $this->getFieldValue($currentData,$record,$event_id,$currentMetaData[$languageField]['form_name'],$languageField,$repeat_instance);
            }

            $additionalParams = (filter_var($senderValue, FILTER_VALIDATE_EMAIL) ? "-f ".$senderValue : null);

            if ($emailValue != "" && $emailLanguage != "" && $subjectValue != "" && filter_var($senderValue,FILTER_VALIDATE_EMAIL)) {
                $emailsArray = explode(",", $emailValue);
                foreach ($emailsArray as $email) {
                    $email = trim($email);
                    if (filter_var($email,FILTER_VALIDATE_EMAIL)) {
                        $autoRecordID = $this->framework->addAutoNumberedRecord($destinationProject);
                        $hashInfo = $this->resetSurveyAndGetCodes($destinationProject,$autoRecordID,$surveyForm);
                        $hash = $hashInfo['hash'];
                        if ($hash != "") {
                            $surveyLink = "<a href='https://".$_SERVER['SERVER_NAME']."/surveys/?s=".$hash."'>https://".$_SERVER['SERVER_NAME']."/surveys/?s=".$hash."</a>";
                            $emailLanguage = str_replace("SURVEY_LINK",$surveyLink,$emailLanguage);
                            if ($sendDateField != "" && $sendDateValue != "") {
                                $sendDate = date('Y-m-d H:i:s',strtotime($sendDateValue));
                            }
                            else {
                                $sendDate = date('Y-m-d H:i:s');
                            }
                            //mail($email,"Test Survey Invitation",$emailLanguage,$headers,$additionalParams);
                            $this->addSurveyToScheduler($autoRecordID,$email,$surveyId,$sendDate,$hash,$subjectValue,$emailLanguage,$senderValue);
                        }
                    }
                }
            }
        }
    }

    function getFieldValue($recordData,$record_id,$event_id,$form,$fieldname,$instance) {
        if (isset($recordData[$record_id]['repeat_instances'][$event_id][$form][$instance][$fieldname])) {
            return $recordData[$record_id]['repeat_instances'][$event_id][$form][$instance][$fieldname];
        }
        elseif (isset($recordData[$record_id][$event_id][$fieldname])) {
            return $recordData[$record_id][$event_id][$fieldname];
        }
        return "";
    }

    function addSurveyToScheduler($recordID, $emailAddress, $surveyId, $sendDate, $hash, $subject, $emailBody, $senderEmail) {
        $sql = "SELECT p.participant_id
						FROM redcap_surveys_participants p
						WHERE p.hash = '$hash'";
        //echo "$sql<br/>";
        $participantId = db_result(db_query($sql),0);

        $sql = "INSERT INTO redcap_surveys_emails (survey_id, email_subject, email_content, email_static, delivery_type)
        		VALUES ($surveyId, '".$subject."', '".str_replace("'","",$emailBody)."', '".$senderEmail."', 'EMAIL')";
        //echo "$sql<br/>";
        if(!db_query($sql)) throw new \Exception("Error: ".db_error()." <br />$sql<br />");
        $emailId = db_insert_id();

        ##insert into emails recipient table
        $sql = "INSERT INTO redcap_surveys_emails_recipients (email_id, participant_id, static_email, delivery_type)
                VALUES ($emailId, $participantId, '{$emailAddress}', 'EMAIL')";
        //echo "$sql<br/>";
        if(!db_query($sql)) throw new \Exception("Error: ".db_error()." <br />$sql<br />");
        $e_r_id = db_insert_id();

        /*$sql = "SELECT email_recip_id
				FROM redcap_surveys_emails_recipients
				WHERE participant_id = $participantId";
        //echo "$sql<br/>";
        $query = db_query($sql);

        $e_r_id = db_fetch_array($query)[0];*/

        ##insert into the surveys scheduler queue table
        $sql = "INSERT INTO redcap_surveys_scheduler_queue (email_recip_id, reminder_num, record, scheduled_time_to_send, status)
                VALUES ($e_r_id, '0', '{$recordID}','".$sendDate."' ,'QUEUED')";
        //echo "$sql<br/>";
        if(!db_query($sql))  throw new \Exception("Error: ".db_error()." <br />$sql<br />");
    }
}