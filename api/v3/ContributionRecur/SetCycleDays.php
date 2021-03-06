<?php
/**
 * ContributionRecur.SetCycleDays API
 * Migratie Amnesty International Vlaanderen
 * Get cycle days from Espadon import file.
 * If cycle_day is less then 15, set to 7 in recurring
 * Else set to 21 in recurring
 *
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_contribution_recur_setcycledays($params) {
  $countSeven = 0;
  $countTwentyOne = 0;
  $logger = new CRM_MigrateLogger("cycle_days_log");
  /*
   * get cycle days and then retrieve recurring contribution with all relevant data via pledge
   */
  $cycleQuery = "SELECT * FROM aivl_cycle_days WHERE processed IS NULL LIMIT 1000";
  $daoCycle = CRM_Core_DAO::executeQuery($cycleQuery);
  while ($daoCycle->fetch()) {
    _setProcessed($daoCycle->mandaat_code);
    _addCiviCampaignId($daoCycle);
    if (_validCheck($daoCycle, $logger)) {
      $cycleDay = _calcCycleDay($daoCycle->cycle_day);
      if ($cycleDay == 7) {
        $countSeven++;
      } else {
        $countTwentyOne++;
      }
      $recurParams = array(
        1 => array("civicrm_contribution_recur", "String"),
        2 => array($cycleDay, "Integer"),
        3 => array((string) $daoCycle->mandaat_code, "String"));
      $recurQuery = "UPDATE civicrm_contribution_recur recur
JOIN civicrm_sdd_mandate sdd ON recur.id = sdd.entity_id AND sdd.entity_table = %1
JOIN civicrm_value_sepa_direct_debit_2 custom ON sdd.reference = custom.mandate_3
SET recur.cycle_day = %2
WHERE sdd.reference = %3";
      try {
        CRM_Core_DAO::executeQuery($recurQuery, $recurParams);
        $logger->logMessage("INFO", "Set cycle days to ".$cycleDay." for recurring contribution with mandate "
            .$daoCycle->mandaat_code);

      } catch (CiviCRM_API3_Exception $ex) {
        $logger->logMessage("WARNING", "Could not update recurring contribution for mandate ".$daoCycle->mandaat_code);
      }
    }
  }
  $returnValues = "Cycle days aangepast, ".$countSeven." op 7 gezet en ".$countTwentyOne." op 21";
  return civicrm_api3_create_success($returnValues, $params, 'ContributionRecur', 'SetCycleDays');
}

/**
 * Function to calculate the cycle days based on Espadon. 1 to 15 becomes 7, 16 to 31 becomes 21
 * @param $sourceCycleDay
 * @return int
 */
function _calcCycleDay($sourceCycleDay) {
  $checkCycleDay = (int) $sourceCycleDay;
  if ($checkCycleDay < 16) {
    return 7;
  } else {}
  return 21;
}

/**
 * Check if cycle days dao seems valid based on mandate, campaign_id and external_identifier
 *
 * @param $dao
 * @return bool
 */
function _validCheck($dao, $logger) {
  try {
    $countContact = civicrm_api3('Contact', 'Getcount', array('external_identifier' => $dao->external_id));
    if ($countContact == 0) {
      $logger->logMessage("WARNING", "Could not find any contact with external_identifier ".$dao->external_id
          .", no cycle day set for mandate ".$dao->mandaat_code);
      return FALSE;
    }
  } catch (CiviCRM_API3_Exception $ex) {
    $logger->logMessage("WARNING", "Error from API when trying to do a Contact Getcount for external_identifier "
        .$dao->external_id." and mandate ".$dao->mandaat_code);
    return FALSE;
  }

  try {
    $countPledge = civicrm_api3('Pledge', 'Getcount', array('campaign_id' => $dao->civiCampaignId));
    if ($countPledge == 0) {
      $logger->logMessage("WARNING", "Could not find any pledge with campaign_id ".$dao->civiCampaignId
          ." (bron ".$dao->campaign_id."), no cycle day set for mandate ".$dao->mandaat_code);
      return FALSE;
    }
  } catch (CiviCRM_API3_Exception $ex) {
    $logger->logMessage("WARNING", "Error from API when trying to do a Pledge Getcount for campaign_id "
        .$dao->civiCampaignId." and mandate ".$dao->mandaat_code);
    return FALSE;
  }

  try {
    $countRecur = civicrm_api3('ContributionRecur', 'Getcount', array('campaign_id' => $dao->civiCampaignId));
    if ($countRecur == 0) {
      $logger->logMessage("WARNING", "Could not find any recurring contribution with campaign_id ".$dao->civiCampaignId
          ." (bron ".$dao->campaign_id."), no cycle day set for mandate ".$dao->mandaat_code);
      return FALSE;
    }
  } catch (CiviCRM_API3_Exception $ex) {
    $logger->logMessage("WARNING", "Error from API when trying to do a ContributionRecur Getcount for campaign_id "
        .$dao->civiCampaignId." and mandate ".$dao->mandaat_code);
    return FALSE;
  }

  try {
    $countMandate = civicrm_api3('SepaMandate', 'Getcount', array('reference' => $dao->mandaat_code));
    if ($countMandate == 0) {
      $logger->logMessage("WARNING", "Could not find any sdd mandate with reference ".$dao->mandaat_code
          .", no cycle day set for mandate.");
      return FALSE;
    }
  } catch (CiviCRM_API3_Exception $ex) {
    $logger->logMessage("WARNING", "Error from API when trying to do a SepaMandate Getcount for reference "
        . $dao->mandaat_code.", error message: ".$ex->getMessage());
    return FALSE;
  }

  $pledgeQuery = "SELECT COUNT(*) AS pledgeCount FROM civicrm_value_sepa_direct_debit_2 WHERE mandate_3 = %1";
  $pledgeParams = array(1 => array($dao->mandaat_code, 'String'));
  $daoPledge = CRM_Core_DAO::executeQuery($pledgeQuery, $pledgeParams);
  if ($daoPledge->fetch()) {
    if ($daoPledge->pledgeCount == 0) {
      $logger->logMessage("WARNING", "Could not find any pledge with mandate ".$dao->mandaat_code.", no cycle day set");
      return FALSE;
    }
  }
  return TRUE;
}

function _addCiviCampaignId(&$daoCycle) {
  $query = "SELECT id FROM civicrm_campaign WHERE SUBSTR(title,1,4) = %1";
  $params = array(1 => array($daoCycle->campaign_id, 'String'));
  $dao = CRM_Core_DAO::executeQuery($query, $params);
  if ($dao->fetch()) {
    $daoCycle->civiCampaignId = $dao->id;
  }
}

function _setProcessed($mandaatCode) {
  $processedQuery = "UPDATE aivl_cycle_days SET processed = %1 WHERE mandaat_code = %2";
  $processedParams = array(
      1 => array(1, "Integer"),
      2 => array($mandaatCode, "String")
  );
  CRM_Core_DAO::executeQuery($processedQuery, $processedParams);
}

