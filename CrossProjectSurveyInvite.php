<?php

namespace Vanderbilt\CrossProjectSurveyInvite;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;

class CrossProjectSurveyInvite extends AbstractExternalModule
{
    function redcap_data_entry_form($project_id, $record, $instrument, $event_id, $group_id = NULL, $repeat_instance = 1) {
        /*$attributes = \Files::getEdocContentsAttributes(514);
        echo "<pre>";
        print_r($attributes);
        echo "</pre>";
        $lineSplit = explode("\n",$attributes[2]);
        echo "<pre>";
        print_r($lineSplit);
        echo "</pre>";*/
    }

    function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id = NULL, $survey_hash = NULL, $response_id = NULL, $repeat_instance = 1) {
        $emailFields = $this->getProjectSetting('email_field',$project_id);
        $destinationProjects = $this->getProjectSetting('destination_project',$project_id);
        $surveyForms = $this->getProjectSetting('survey_form',$project_id);
        $emailLanguages = $this->getProjectSetting('email_language',$project_id);
        $emailSenders = $this->getProjectSetting('email_sender',$project_id);
        $emailSubjects = $this->getProjectSetting('email_subject',$project_id);
        $supEmailLanguages = $this->getProjectSetting('sup_email_language',$project_id);
        $supEmailSubjects = $this->getProjectSetting('sup_email_subject',$project_id);
        $supEmailSenders = $this->getProjectSetting('sup_email_subject',$project_id);
        $sendDates = $this->getProjectSetting('send_date_field',$project_id);
        $sourceFields = $this->getProjectSetting('source-field',$project_id);
        $destFields = $this->getProjectSetting('destination-field',$project_id);
        $timeOffsets = $this->getProjectSetting('time_offset',$project_id);
        $destEmailFields = $this->getProjectSetting('email_pipe_field',$project_id);
        $recordFieldMappings = $this->getProjectSetting('record_id_mapping',$project_id);

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
            $supSubjectField = $supEmailSubjects[$index];
            $supLanguageField = $supEmailLanguages[$index];
            $supSenderField = $supEmailSenders[$index];
            $sendDateField = $sendDates[$index];
            $timeOffset = $timeOffsets[$index];
            $destEmailField = $destEmailFields[$index];
            $recordFieldMapping = $recordFieldMappings[$index];

            $projectObject = new \Project($destinationProject);
            $surveyId = $projectObject->forms[$surveyForm]['survey_id'];
            if ($surveyId == "") {
                //echo "Skipping<br/>";
                continue;
            }
            $destMeta = $projectObject->metadata;

            $languageMetaData = $currentMetaData[$languageField];
            $emailMetaData = $currentMetaData[$emailField];

            $fieldList = array($emailField,$senderField,$languageField,$subjectField,$sendDateField,$supSenderField,$supLanguageField,$supSubjectField);
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
            $mappingValue = $this->getFieldValue($currentData,$record,$event_id,$currentMetaData[$recordFieldMapping]['form_name'],$recordFieldMapping,$repeat_instance);
            $supSenderValue = $this->getFieldValue($currentData,$record,$event_id,$currentMetaData[$supSenderField]['form_name'],$supSenderField,$repeat_instance);
            $supLanguageValue = $this->getFieldValue($currentData,$record,$event_id,$currentMetaData[$supLanguageField]['form_name'],$supLanguageField,$repeat_instance);
            $supSubjectValue = $this->getFieldValue($currentData,$record,$event_id,$currentMetaData[$supSubjectField]['form_name'],$supSubjectField,$repeat_instance);

            if ($languageMetaData['element_type'] == 'descriptive') {
                $emailLanguage = $languageMetaData['element_label'];
            }
            else {
                $emailLanguage = $this->getFieldValue($currentData,$record,$event_id,$currentMetaData[$languageField]['form_name'],$languageField,$repeat_instance);
            }

            $emailLanguage = \Piping::replaceVariablesInLabel($emailLanguage,$record,$event_id,$repeat_instance,$currentData);

            $additionalParams = (filter_var($senderValue, FILTER_VALIDATE_EMAIL) ? "-f ".$senderValue : null);

            if ($emailValue != "" && $emailLanguage != "" && $subjectValue != "" && filter_var($senderValue,FILTER_VALIDATE_EMAIL)) {
                if ($emailMetaData['element_type'] == "file") {
                    $emailsArray = array();
                    $supEmailsArray = array();
                    $attributes = \Files::getEdocContentsAttributes($emailValue);
                    if ($attributes[0] == "text/plain" && substr($attributes[1],-3,3) == "csv") {
                        $lineSplit = explode("\n",$attributes[2]);
                        foreach ($lineSplit as $lineNum => $commaEmails) {
                            $split = explode(",", $commaEmails);
                            if (filter_var(trim($split[0]), FILTER_VALIDATE_EMAIL)) {
                                $emailsArray[$lineNum] = trim($split[0]);
                            }
                            if (filter_var(trim($split[1]), FILTER_VALIDATE_EMAIL)) {
                                $supEmailsArray[$lineNum] = trim($split[1]);
                            }
                        }
                    }
                }
                else {
                    $emailsArray = explode(",", str_replace(' ','',$emailValue));
                }

                $dataParameters = array("project_id" => $destinationProject, "return_format" => 'json', "fields" => array($recordFieldMapping,$projectObject->table_pk,$destEmailField), "filterLogic" => "[".$recordFieldMapping."] = '".$record."'");
                $destinationData = json_decode(\REDCap::getData($dataParameters),true);
                $autoRecordID = "";
                $emailInstance = 0;
                $existingEmails = array();

                foreach ($destinationData as $instanceData) {
                    if ($instanceData[$recordFieldMapping] != $record) continue;
                    if ($instanceData['redcap_repeat_instance'] > $emailInstance) {
                        $emailInstance = $instanceData['redcap_repeat_instance'];
                    }
                    if (!in_array($instanceData[$destEmailField],$existingEmails)) {
                        $existingEmails[] = $instanceData[$destEmailField];
                    }
                    $autoRecordID = $instanceData[$projectObject->table_pk];
                }

                if ($autoRecordID == "") {
                    $autoRecordID = $this->addAutoNumberedRecord($destinationProject);
                }
                $emailInstance++;

                foreach ($emailsArray as $emailIndex => $email) {
                    $email = trim($email);
                    if (filter_var($email,FILTER_VALIDATE_EMAIL)) {
                        if (in_array($email,$existingEmails)) continue;
                        $existingEmails[] = $email;
                        /*$hashInfo = $this->resetSurveyAndGetCodes($destinationProject,$autoRecordID,$surveyForm);
                        $hash = $hashInfo['hash'];*/

                        $surveyLink =$this->passthruToSurvey($destinationProject,db_real_escape_string($autoRecordID),$projectObject->firstEventId,db_real_escape_string($surveyForm),true,$emailInstance);
                        echo "Survey link: $surveyLink<br/>";
                        if ($surveyLink != "") {
                            $messageLink = "<a href='$surveyLink'>$surveyLink</a>";
                            $linkPart = explode("?s=",$surveyLink);
                            $hash = $linkPart[count($linkPart) - 1];
                            $emailLanguage = str_replace("SURVEY_LINK", $messageLink, $emailLanguage);

                            $emailLanguage = preg_replace("/[^a-zA-Z0-9~!@#$%^?&*{}\[\]()`\~|_+\/<>=\'\";\\\:.,\- ]+/", ' ', $emailLanguage);
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
                                $sendDate = gmdate("Y-m-d H:i:s", $serverDate+(intval($timeOffset)*60*60));
                            }
                            foreach ($sourceFieldList as $sourceIndex => $sourceField) {
                                if (isset($destFieldList[$sourceField])) {
                                    $destField = $destFieldList[$sourceField]['field_name'];

                                    $sourceValue = $this->getFieldValue($currentData,$record,$event_id,$currentMetaData[$sourceField]['form_name'],$sourceField,$repeat_instance);

                                    $instrumentRepeats = $projectObject->isRepeatingFormOrEvent($projectObject->firstEventId,$destFieldList[$sourceField]['form_name']);
                                    $saveArray = array($sourceIndex=>array($projectObject->table_pk=>$autoRecordID,'redcap_event_name'=>$projectObject->firstEventId));
                                    if (is_array($sourceValue)) {
                                        foreach ($sourceValue as $index => $actualValue) {
                                            $saveArray[$sourceIndex][$destField."___".$index] = $actualValue;
                                        }
                                    }
                                    else {
                                        $saveArray[$sourceIndex][$destField] = $sourceValue;
                                    }
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
                                    $saveArray[$sourceIndex]['redcap_repeat_instrument'] = $destMeta[$destEmailField]['form_name'];
                                }

                                $destResult = \REDCap::saveData($destinationProject,'json',json_encode($saveArray));
                            }
                            if ($recordFieldMapping != "" && in_array($recordFieldMapping,array_keys($destMeta))) {
                                $saveArray = array($sourceIndex=>array($projectObject->table_pk=>$autoRecordID,'redcap_event_name'=>$projectObject->firstEventId,$recordFieldMapping=>$record));
                                if ($instrumentRepeats) {
                                    $saveArray[$sourceIndex]['redcap_repeat_instance'] = $emailInstance;
                                    $saveArray[$sourceIndex]['redcap_repeat_instrument'] = $destMeta[$recordFieldMapping]['form_name'];
                                }

                                $destResult = \REDCap::saveData($destinationProject,'json',json_encode($saveArray));
                            }

                            if ($hash != "") {
                                $this->addSurveyToScheduler($autoRecordID, $email, $surveyId, $sendDate, $hash, db_real_escape_string($subjectValue), db_real_escape_string($emailLanguage), db_real_escape_string($senderValue), $emailInstance);
                                $supLanguageValue = \Piping::replaceVariablesInLabel($supLanguageValue,$record,$event_id,$repeat_instance,$currentData);
                                $supLanguageValue = str_replace("PART_EMAIL", $email, $supLanguageValue);
                                $supLanguageValue = preg_replace("/[^a-zA-Z0-9~!@#$%^?&*{}\[\]()`\~|_+\/<>=\'\";\\\:.,\- ]+/", ' ', $supLanguageValue);
                                #Remove weird character created through character encoding mismatch between Rich Text field and the mysql database creating Â characters from &nbsp;
                                $supLanguageValue = str_replace(chr(194),'',$supLanguageValue);

                                if ($supLanguageValue != "" && $supSenderValue != "" && $supSubjectValue != "" && $supEmailsArray[$emailIndex] != "") {
                                    $this->addSurveyToScheduler($autoRecordID, $supEmailsArray[$emailIndex], $surveyId, $sendDate, $hash, db_real_escape_string($supSubjectValue), db_real_escape_string($supLanguageValue), db_real_escape_string($supSenderValue), $emailInstance, 0);
                                }
                            }
                            $emailInstance++;
                        }
                    }
                }
            }
        }
        //$this->exitAfterHook();
    }

    function getFieldValue($recordData,$record_id,$event_id,$form,$fieldname,$instance) {
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

    function addSurveyToScheduler($recordID, $emailAddress, $surveyId, $sendDate, $hash, $subject, $emailBody, $senderEmail, $instance = '1', $appendSurveyLink = 1) {
        $sql = "SELECT p.participant_id
						FROM redcap_surveys_participants p
						WHERE p.hash = '$hash'";
        //echo "$sql<br/>";
        $participantId = db_result(db_query($sql),0);

        $sql = "INSERT INTO redcap_surveys_emails (survey_id, email_subject, email_content, email_static, delivery_type, append_survey_link)
        		VALUES ($surveyId, '".$subject."', '$emailBody', '".$senderEmail."', 'EMAIL', '".$appendSurveyLink."')";
        //echo "$sql<br/>";
        if(!db_query($sql)) $this->log("Error: ".db_error()." <br />$sql<br />");
        $emailId = db_insert_id();

        ##insert into emails recipient table
        $sql = "INSERT INTO redcap_surveys_emails_recipients (email_id, participant_id, static_email, delivery_type)
                VALUES ($emailId, $participantId, '{$emailAddress}', 'EMAIL')";
        //echo "$sql<br/>";
        if(!db_query($sql)) $this->log("Error: ".db_error()." <br />$sql<br />");
        $e_r_id = db_insert_id();

        /*$sql = "SELECT email_recip_id
				FROM redcap_surveys_emails_recipients
				WHERE participant_id = $participantId";
        //echo "$sql<br/>";
        $query = db_query($sql);

        $e_r_id = db_fetch_array($query)[0];*/

        ##insert into the surveys scheduler queue table
        $sql = "INSERT INTO redcap_surveys_scheduler_queue (email_recip_id, reminder_num, record, instance, scheduled_time_to_send, status)
                VALUES ($e_r_id, '0', '{$recordID}','{$instance}','".$sendDate."' ,'QUEUED')";
        //echo "$sql<br/>";
        if(!db_query($sql))  $this->log("Error: ".db_error()." <br />$sql<br />");
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
						VALUES (?, ?, ?, ?, ?, ?)",array($project_id,$event_id,$record,db_real_escape_string($field_name),$edocID,($instance == '1' ? NULL : $instance)));
    }

    private function getSurveyFormAndId($project_id, $formName = "") {
        // Get survey_id, form status field, and save and return setting
        $sql = "SELECT s.survey_id, s.form_name, s.save_and_return
		 		FROM redcap_projects p, redcap_surveys s, redcap_metadata m
					WHERE p.project_id = ".$project_id."
						AND p.project_id = s.project_id
						AND m.project_id = p.project_id
						AND s.form_name = m.form_name
						".($formName != "" ? (is_numeric($formName) ? "AND s.survey_id = '$formName'" : "AND s.form_name = '$formName'") : "")
            ." LIMIT 1";

        $q = db_query($sql);
        $formName = db_result($q, 0, 'form_name');
        $surveyId = db_result($q, 0, 'survey_id');

        return array($formName, $surveyId);
    }

    private function passthruToSurvey($project_id, $record, $event_id, $surveyFormName = "", $dontCreateForm = false, $instance = "1", $dontResetSurvey = false) {
        // Check to make sure instance is greater than or equal to 1.  Instance with value "" causes weird behavior i.e.  2 instances getting created with "" and 0.
        $instance = is_numeric($instance) ? (int)$instance : 1;

        // Get survey_id, form status field
        list($surveyFormName, $surveyId) = $this->getSurveyFormAndId($project_id, db_real_escape_string($surveyFormName));

        if($surveyId == "") {
            if($dontCreateForm) {
                return false;
            }
            else {
                $this->log("Error: Survey ID not found<br />{$record} : $surveyFormName<br />");
            }
        }


        ## Search for a participant and response id for the given survey and record
        $sql = "SELECT p.participant_id, p.hash, r.return_code, r.response_id, COALESCE(p.participant_email,'NULL') as participant_email
				FROM redcap_surveys_participants p, redcap_surveys_response r
				WHERE p.survey_id = '$surveyId'
					AND p.participant_id = r.participant_id
					AND r.record = '".prep($record)."'
					AND r.instance='$instance'";
        //echo "$sql<br/>";
        $participantQuery = db_query($sql);
        $rows = [];
        while($row = db_fetch_assoc($participantQuery)) {
            $rows[] = $row;
        }
        $participantId = $rows[0]['participant_id'];
        $responseId = $rows[0]['response_id'];
        ## Create participant and return code if doesn't exist yet
        if($participantId == "" || $responseId == "") {

            $hash = $this->generateUniqueRandomSurveyHash();
            ## Insert a participant row for this survey
            $sql = "INSERT INTO redcap_surveys_participants (survey_id, event_id, participant_email, participant_identifier, hash)
					VALUES ($surveyId,".prep($event_id).", '', null, '$hash')";
            //echo "$sql<br/>";
            if(!db_query($sql)) echo "Error: ".db_error()." <br />$sql<br />";
            $participantId = db_insert_id();

            ## Insert a response row for this survey and record
            $returnCode = generateRandomHash();

            $sql = "INSERT INTO redcap_surveys_response (participant_id, record, instance, first_submit_time, return_code)
					VALUES ($participantId, ".prep($record).", '$instance', NULL,'$returnCode')";

            //echo "$sql<br/>";
            if(!db_query($sql)) echo "Error: ".db_error()." <br />$sql<br />";
            $responseId = db_insert_id();
        }
        ## Reset response status if it already exists
        else {
            ## If more than one exists, delete any that are responses to public survey links
            if(db_num_rows($participantQuery) > 1) {
                foreach($rows as $thisRow) {
                    if($thisRow["participant_email"] == "NULL" && $thisRow["response_id"] != "") {
                        $sql = "DELETE FROM redcap_surveys_response
								WHERE response_id = ".$thisRow["response_id"];
                        if(!db_query($sql)) echo "Error: ".db_error()." <br />$sql<br />";
                    }
                    else {
                        $row = $thisRow;
                    }
                }
            }
            else {
                $row = $rows[0];
            }
            $returnCode = $row['return_code'];
            $hash = $row['hash'];
            $participantId = "";

            if($returnCode == "") {
                $returnCode = generateRandomHash();
            }

            ## If this is only as a public survey link, generate new participant row
            if($row["participant_email"] == "NULL") {
                $hash = self::generateUniqueRandomSurveyHash();

                ## Insert a participant row for this survey
                $sql = "INSERT INTO redcap_surveys_participants (survey_id, event_id, participant_email, participant_identifier, hash)
						VALUES ($surveyId,".prep($event_id).", '', null, '$hash')";

                if(!db_query($sql)) echo "Error: ".db_error()." <br />$sql<br />";
                $participantId = db_insert_id();
            }

            if($dontResetSurvey) {
                // Only update returnCode if on public survey link
                $sql = "UPDATE redcap_surveys_participants p, redcap_surveys_response r
						SET r.return_code = '".prep($returnCode)."'".
                    ($participantId == "" ? "" : ", r.participant_id = '$participantId'")."
						WHERE p.survey_id = $surveyId
							AND p.event_id = ".prep($event_id)."
							AND r.participant_id = p.participant_id
							AND r.record = '".prep($record)."'
							AND r.instance = '$instance'";
            }
            else {
                // Set the response as incomplete in the response table, update participantId if on public survey link
                $sql = "UPDATE redcap_surveys_participants p, redcap_surveys_response r
						SET r.completion_time = null,
							r.first_submit_time = NULL,
							r.return_code = '".prep($returnCode)."'".
                    ($participantId == "" ? "" : ", r.participant_id = '$participantId'")."
						WHERE p.survey_id = $surveyId
							AND p.event_id = ".prep($event_id)."
							AND r.participant_id = p.participant_id
							AND r.record = '".prep($record)."'
							AND r.instance = '$instance'";
            }
            db_query($sql);
        }
        $surveyLink = APP_PATH_SURVEY_FULL . "?s=$hash";

        @db_query("COMMIT");

        if($dontCreateForm) {
            return $surveyLink;
        }
        else {
            // Set the response as incomplete in the data table
            $sql = "UPDATE redcap_data
				SET value = '0'
				WHERE project_id = ".prep($project_id)."
					AND record = '".prep($record)."'
					AND event_id = ".prep($event_id)."
					AND field_name = '{$surveyFormName}_complete' 
					AND instance =" . $instance;
            $q = db_query($sql);
            // Log the event (if value changed)
            if ($q && db_affected_rows() > 0) {
                if(function_exists("log_event")) {
                    \log_event($sql,"redcap_data","UPDATE",$record,"{$surveyFormName}_complete = '0'","Update record");
                }
                else {
                    \Logging::logEvent($sql,"redcap_data","UPDATE",$record,"{$surveyFormName}_complete = '0'","Update record");
                }
            }

//			echo "Return $returnCode ~ $surveyLink <br />";
            ## Build invisible self-submitting HTML form to get the user to the survey
            echo "<html><body>
				<form name='passthruform' action='$surveyLink' method='post' enctype='multipart/form-data'>
				".($returnCode == "NULL" ? "" : "<input type='hidden' value='".$returnCode."' name='__code'/>")."
				<input type='hidden' value='1' name='__prefill' />
				</form>
				<script type='text/javascript'>
					document.passthruform.submit();
				</script>
				</body>
				</html>";
            return false;
        }
    }
}