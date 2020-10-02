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
        $sourceFields = $this->getProjectSetting('source-field');
        $destFields = $this->getProjectSetting('destination-field');
        $timeOffsets = $this->getProjectSetting('time_offset');
        $destEmailFields = $this->getProjectSetting('email_pipe_field');

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
            $timeOffset = $timeOffsets[$index];
            $destEmailField = $destEmailFields[$index];

            $projectObject = new \Project($destinationProject);
            $surveyId = $projectObject->forms[$surveyForm]['survey_id'];
            if ($surveyId == "") {
                //echo "Skipping<br/>";
                continue;
            }
            $destMeta = $projectObject->metadata;

            $languageMetaData = $currentMetaData[$languageField];
            $emailMetaData = $currentMetaData[$emailField];

            $fieldList = array($emailField,$senderField,$languageField,$subjectField,$sendDateField);
            $destFieldList = array();

            foreach ($sourceFields[$index] as $subIndex => $sourceField) {
                $destField = $destFields[$index][$subIndex];
                if (!in_array($destField, array_keys($destMeta))) continue;
                if ($currentMetaData[$sourceField]['element_enum'] != $destMeta[$destField]['element_enum'] || $currentMetaData[$sourceField]['element_type'] != $destMeta[$destField]['element_type'] || $currentMetaData[$sourceField]['element_validation_type'] != $destMeta[$destField]['element_validation_type']) continue;

                $fieldList[] = $sourceField;
                $sourceFieldList[] = $sourceField;
                $destFieldList[$sourceField] = $destMeta[$destField];
            }

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
                if ($emailMetaData['element_type'] == "file") {
                    $emailsArray = array();
                    $attributes = \Files::getEdocContentsAttributes($emailValue);
                    if ($attributes[0] == "text/plain" && substr($attributes[1],-3,3) == "csv") {
                        $split = explode(",",$attributes[2]);
                        foreach ($split as $email) {
                            if (filter_var(trim($email),FILTER_VALIDATE_EMAIL)) {
                                $emailsArray[] = trim($email);
                            }
                        }
                    }
                }
                else {
                    $emailsArray = explode(",", $emailValue);
                }

                $autoRecordID = $this->addAutoNumberedRecord($destinationProject);
                $emailInstance = 1;
                foreach ($emailsArray as $emailIndex => $email) {
                    $email = trim($email);
                    if (filter_var($email,FILTER_VALIDATE_EMAIL)) {
                        $hashInfo = $this->resetSurveyAndGetCodes($destinationProject,$autoRecordID,$surveyForm);
                        $hash = $hashInfo['hash'];
                        if ($hash != "") {
                            $surveyLink = "<a href='https://".$_SERVER['SERVER_NAME']."/surveys/?s=".$hash."'>https://".$_SERVER['SERVER_NAME']."/surveys/?s=".$hash."</a>";
                            $emailLanguage = str_replace("SURVEY_LINK",$surveyLink,$emailLanguage);
                            //$emailLanguage = preg_replace("/[^a-zA-Z0-9~!@#$%^&*()_+\/<>=\'\";:,\- ]+/", ' ', $emailLanguage);
                            #Remove weird character created through character encoding mismatch between Rich Text field and the mysql database creating Â characters from &nbsp;
                            $emailLanguage = str_replace(chr(194),'',$emailLanguage);
                            if ($sendDateField != "" && $sendDateValue != "") {
                                $sendDate = date('Y-m-d H:i:s',strtotime($sendDateValue));
                            }
                            else {
                                $sendDate = date('Y-m-d H:i:s');
                            }

                            if (is_numeric($timeOffset)) {
                                $serverDate = strtotime($sendDate);
                                $sendDate = gmdate("Y-d-m H:i:s", $serverDate+(intval($timeOffset)*60*60));
                            }

                            foreach ($sourceFieldList as $sourceIndex => $sourceField) {
                                if (isset($destFieldList[$sourceField])) {
                                    $destField = $destFieldList[$sourceField]['field_name'];

                                    $sourceValue = $this->getFieldValue($currentData,$record,$event_id,$currentMetaData[$sourceField]['form_name'],$sourceField,$repeat_instance);

                                    $instrumentRepeats = $projectObject->isRepeatingFormOrEvent($projectObject->firstEventId,$destFieldList[$sourceField]['form_name']);
                                    $saveArray = array($sourceIndex=>array($projectObject->table_pk=>$autoRecordID,'redcap_event_name'=>$projectObject->firstEventId,$destField=>$sourceValue));
                                    if ($instrumentRepeats) {
                                        $saveArray[$sourceIndex]['redcap_repeat_instance'] = $emailInstance;
                                        $saveArray[$sourceIndex]['redcap_repeat_instrument'] = $destFieldList[$sourceField]['form_name'];
                                    }

                                    if ($currentMetaData[$sourceField]['element_type'] == "file" && $destFieldList[$sourceField]['element_type'] == "file") {
                                        list($oldPID,$copyEdoc) = $this::copyEdoc($projectObject->project_id,$sourceValue);
                                        $saveArray[$sourceIndex][$destField] = $copyEdoc;
                                        $this->saveEdocIDToField($projectObject->project_id,$projectObject->firstEventId,$autoRecordID,$destField,$copyEdoc,$emailInstance);
                                    }
                                    $destResult = \REDCap::saveData($destinationProject,'json',json_encode($saveArray));
                                }
                            }
                            if ($destEmailField != "" && in_array($destEmailField,array_keys($destMeta))) {
                                $saveArray = array($sourceIndex=>array($projectObject->table_pk=>$autoRecordID,'redcap_event_name'=>$projectObject->firstEventId,$destEmailField=>$email));
                                if ($instrumentRepeats) {
                                    $saveArray[$sourceIndex]['redcap_repeat_instance'] = $emailInstance;
                                    $saveArray[$sourceIndex]['redcap_repeat_instrument'] = $destFieldList[$sourceField]['form_name'];
                                }

                                $destResult = \REDCap::saveData($destinationProject,'json',json_encode($saveArray));
                            }
                            $this->addSurveyToScheduler($autoRecordID,$email,$surveyId,$sendDate,$hash,$subjectValue,$emailLanguage,$senderValue);
                        }
                    }
                    $emailInstance++;
                }
            }
        }
        //$this->exitAfterHook();
    }

    function getFieldValue($recordData,$record_id,$event_id,$form,$fieldname,$instance) {
        echo "Record: $record_id,Event: $event_id,Form:$form,Instance: $instance,Field:$fieldname<br/>";
        if (isset($recordData[$record_id]['repeat_instances'][$event_id][$form][$instance][$fieldname])) {
            return $recordData[$record_id]['repeat_instances'][$event_id][$form][$instance][$fieldname];
        }
        elseif (isset($recordData[$record_id]['repeat_instances'][$event_id][''][$instance][$fieldname])) {
            return $recordData[$record_id]['repeat_instances'][$event_id][''][$instance][$fieldname];
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

    private static function copyEdoc($pid, $edocId)
    {
        if(empty($edocId)){
            // The stored id is already empty.
            return '';
        }

        $sql = "select * from redcap_edocs_metadata where doc_id = ? and date_deleted_server is null";
        $result = ExternalModules::query($sql, [$edocId]);
        $row = $result->fetch_assoc();

        if(!$row){
            return '';
        }

        $row = ExternalModules::convertIntsToStrings($row);
        $oldPid = $row['project_id'];
        if($oldPid === $pid){
            // This edoc is already associated with this project.  No need to recreate it.
            $newEdocId = $edocId;
        }
        else{
            $newEdocId = copyFile($edocId, $pid);
        }

        return [
            $oldPid,
            (string)$newEdocId // We must cast to a string to avoid an issue on the js side when it comes to handling file fields if stored as integers.
        ];
    }

    function saveEdocIDToField($project_id,$event_id,$record,$field_name,$edocID,$instance = 1) {
        //$instance = ($instance == '1' ? "NULL" : $instance);
        //echo "Instance is $instance<br/>";
        $this->query("INSERT INTO redcap_data (project_id, event_id, record, field_name, value, instance) 
						VALUES (?, ?, ?, ?, ?, ?)",array($project_id,$event_id,$record,$field_name,$edocID,($instance == '1' ? NULL : $instance)));
    }
}