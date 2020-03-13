<?php

namespace Vanderbilt\CrossProjectSurveyInvite;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;

class CrossProjectSurveyInvite extends AbstractExternalModule
{
    function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id = NULL, $survey_hash = NULL, $response_id = NULL, $repeat_instance = 1) {
        $emailFields = $this->getProjectSetting('email_field');
        $destinationProjects = $this->getProjectSetting('destination_project');
        $surveyForms = $this->getProjectSetting('survey_form');
        $emailLanguages = $this->getProjectSetting('email_language');

        $currentProject = new \Project($project_id);
        $currentMetaData = $currentProject->metadata;

        foreach ($destinationProjects as $index => $destinationProject) {
            $emailField = $emailFields[$index];
            $surveyForm = $surveyForms[$index];
            $emailLanguage = $emailLanguages[$index];
            $emailForm = $currentMetaData[$emailField]['form_name'];
            $emailFormRepeats = $currentProject->isRepeatingFormOrEvent($event_id,$emailForm);

            $fieldList = array($emailField);

            $currentData = \REDCap::getData($project_id, 'array', array($record), $fieldList);
            $emailValue = "";
            if ($emailFormRepeats) {
                $emailValue = $currentData[$record]['repeat_instances'][$event_id][$emailForm][$repeat_instance][$emailField];
            }
            else {
                $emailValue = $currentData[$record][$event_id][$emailField];
            }

            if ($emailValue != "") {
                $emailsArray = explode(",", $emailValue);
                foreach ($emailsArray as $email) {
                    $email = trim($email);
                    if (filter_var($email,FILTER_VALIDATE_EMAIL)) {
                        $autoRecordID = $this->framework->addAutoNumberedRecord($destinationProject);
                        echo "Dest: $destinationProject, Record: $autoRecordID, Form: $surveyForm<br/>";
                        $hashInfo = $this->resetSurveyAndGetCodes($destinationProject,$autoRecordID,$surveyForm);
                        $hash = $hashInfo['hash'];
                        if ($hash != "") {
                            $surveyLink = $_SERVER['SERVER_NAME']."/redcap/surveys?s=".$hash;
                            $emailLanguage = str_replace("SURVEY_LINK",$surveyLink,$emailLanguage);
                            mail($email,"Test Survey Invitation",$emailLanguage);
                        }
                    }
                }
            }
            exit;
        }
    }
}