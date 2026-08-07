<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/../../ots/session_handler.php';
require_once __DIR__ . '/../../ots/constant.php';
require_once __DIR__ . '/../../ots/i18n/' . GetCurrentLanguage() . '.php';

function SendJsonError($statusCode, $message)
{
  if (ob_get_length())
  {
    ob_clean();
  }
  http_response_code($statusCode);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(array("status" => "error", "error_text" => $message), JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}

function ReadDateParam($name, $default)
{
  $value = isset($_GET[$name]) ? substr((string)$_GET[$name], 0, 10) : $default;
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value))
  {
    SendJsonError(400, "Érvénytelen dátumformátum.");
  }

  $date = DateTime::createFromFormat('!Y-m-d', $value);
  $errors = DateTime::getLastErrors();
  if (!$date || ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value)
  {
    SendJsonError(400, "Érvénytelen dátum.");
  }

  return $date;
}

if (!isset($_SESSION[GC_LOGIN_COOKIE])) 
{
  SendJsonError(401, S__SESSION_TIME_OUT);
}

if (!isset($_SESSION[GN_CHURCH_ID]) || $_SESSION[GN_CHURCH_ID] <= 0)
{
  SendJsonError(403, S__CHURCH_IS_NOT_SET);
}

require_once __DIR__ . '/../../ots/sconnect.php';
require_once __DIR__ . '/../../ots/function.php';
require_once __DIR__ . '/../../ots/penztar_utils.php';

$mn_ChurchId = isset($_GET['church_id']) ? intval($_GET['church_id']) : intval($_SESSION[GN_CHURCH_ID]);
if ($mn_ChurchId <= 0)
{
  SendJsonError(400, S__CHURCH_IS_NOT_SET);
}

$mn_UserRights = isset($_SESSION[GN_USER_RIGHTS]) ? intval($_SESSION[GN_USER_RIGHTS]) : 0;
if ($mn_ChurchId != intval($_SESSION[GN_CHURCH_ID]) && !($mn_UserRights & SDA_L_CONFERENCE_ROLES))
{
  if (!isset($_SESSION[GN_USER_ID]) || intval($_SESSION[GN_USER_ID]) <= 0)
  {
    SendJsonError(403, S__CHURCH_IS_NOT_SET);
  }

  $mn_AccessCount = 0;
  $mc_AccessSql = "SELECT COUNT(*) CNT FROM ROLES WHERE USER_ID = ? AND CHURCH_ID = ? AND VALID_FROM <= NOW() AND (VALID_TO IS NULL OR VALID_TO >= NOW())";
  if ($ma_AccessRes = $db->query($mc_AccessSql, array(intval($_SESSION[GN_USER_ID]), $mn_ChurchId)))
  {
    if (count($ma_AccessRes) > 0)
    {
      $mx_First = $ma_AccessRes[0];
      $mn_AccessCount = is_object($mx_First) ? intval($mx_First->CNT) : intval($mx_First['CNT']);
    }
  }

  if ($mn_AccessCount <= 0)
  {
    SendJsonError(403, S__CHURCH_IS_NOT_SET);
  }
}

// Frontendről érkező dátumok szigorú validálása.
$md_1stDay = ReadDateParam('start', date('Y-m-01'));
$md_lastSecond = ReadDateParam('end', date('Y-m-t'));
if ($md_lastSecond < $md_1stDay)
{
  SendJsonError(400, "A befejező dátum nem lehet korábbi, mint a kezdő dátum.");
}

$md_MaxEnd = clone $md_1stDay;
$md_MaxEnd->modify("+10 years");
if ($md_lastSecond > $md_MaxEnd)
{
  SendJsonError(400, "A lekérdezési időszak legfeljebb 10 év lehet.");
}
$md_lastSecond->setTime(23, 59, 59);

$mc_Flow = isset($_GET['flow']) ? strtolower($_GET['flow']) : 'bank';
if (!in_array($mc_Flow, array('bank', 'cash', 'both')))
{
  SendJsonError(400, "Érvénytelen forgalom szűrő.");
}

$mc_ViaBankWhere = "T.VIA_BANK <> 0";
$mn_BalanceType = 2;
if ($mc_Flow == 'cash')
{
  $mc_ViaBankWhere = "T.VIA_BANK = 0";
  $mn_BalanceType = 1;
}
elseif ($mc_Flow == 'both')
{
  $mc_ViaBankWhere = "1 = 1";
  $mn_BalanceType = 0;
}

$mc_sql = 
  "SELECT RECORD_ID, MAX(T.CASH_DOCUMENT_NUMBER) AS RECEIPT_NUMBER, MAX(T.DECISION_NUMBER) AS DECISION_NUMBER, " .
    "T.VIA_BANK, IF(T.VIA_BANK <> 0, 'Bank', 'Készpénz') FLOW, " .
    "TRIM(CONCAT(IFNULL((SELECT CONCAT_WS(' ', NAME_PREFIX, NAME, NAME_SUFFIX) FROM PERSONS WHERE id = T.PERSON_ID), ''), " .            
    "       IFNULL((SELECT NAME FROM NAMES_OF_TRANSACTION WHERE id = NAME_ID), ''))) AS DESCRIPTION, " .
  "SUM(IF(T.TYPE in (?), -1 * AMOUNT, AMOUNT)) SUMAMOUNT, MAX(T.DATETIME) DATETIME, U.NAME EDITOR, T.MODIFIED, TT.NAME, " .
  "MAX(T.TYPE) AS TYPE " .
  "FROM TRANSACTIONS T, TRANSACTION_TYPE TT, USERS U " .
  "WHERE " . $mc_ViaBankWhere . " AND CHURCH_ID = ? AND T.TYPE not in (?, ?) AND DATETIME BETWEEN ? AND ? " . 
  "AND T.TYPE = TT.id AND T.EDITED_BY = U.id " .
  "GROUP BY RECORD_ID, T.VIA_BANK, NAME_ID, DATETIME, U.NAME, TT.NAME " .
  "ORDER BY RECORD_ID, DATETIME";

$ma_params = array(GN_TRANSACTION_TYPE_PAYMENT, $mn_ChurchId, GN_TRANSACTION_TYPE_SPECIAL_TARGET_VIA_CONFERENCE, 
  GN_TRANSACTION_TYPE_ACCEPTED_SUBTRACTION, $md_1stDay->format("Y-m-d"),  $md_lastSecond->format("Y-m-d H:i:s"));

class TRecord
{
  public $RECEIPT_NUMBER = "";
  public $DECISION_NUMBER = "";
  public $VIA_BANK = 0;
  public $FLOW = "";
  public $DESCRIPTION = "Nyitó egyenleg";
  public $NAME = "";
  public $SUMAMOUNT = 0;
  public $DATETIME;
  public $EDITOR = "";
  public $MODIFIED = "";
  public $balance = 0;
}

function CleanExcelText($value)
{
  if (!is_string($value))
  {
    return $value;
  }
  return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', ' ', $value);
}

function CleanRecordForExcel(&$record)
{
  $fields = array('RECEIPT_NUMBER', 'DECISION_NUMBER', 'DESCRIPTION', 'NAME', 'EDITOR', 'DATETIME', 'FLOW');
  foreach ($fields as $field)
  {
    if (is_object($record) && isset($record->$field))
    {
      $record->$field = CleanExcelText($record->$field);
    }
    elseif (is_array($record) && isset($record[$field]))
    {
      $record[$field] = CleanExcelText($record[$field]);
    }
  }
}

function AddTransferRow(&$records, DateTime $date, float $amount, string $flow)
{
  $row = new TRecord;
  $row->DESCRIPTION = "Egyházterületnek elutalt";
  $row->NAME = "Átvezetés";
  $row->EDITOR = "";
  $row->FLOW = $flow;
  $row->VIA_BANK = ($flow == "Bank") ? 1 : 0;
  $row->SUMAMOUNT = -1 * $amount;
  $row->DATETIME = $date->format("Y-m-d");
  $records[] = $row;
}

function AddMonthlyTransferRowsToConference($records, DateTime $startDate, DateTime $endDate, int $churchId, $db, string $flow)
{
  $month = StartOfTheMonth($startDate);
  $lastMonth = StartOfTheMonth($endDate);

  while ($month <= $lastMonth)
  {
    $transferred = GetAlreadyTransferredToConference($month, $db, $churchId, USE_CURRENT_MONTH_ONLY, true);
    $cashAmount = isset($transferred[0]) ? (float)$transferred[0] : 0;
    $bankAmount = isset($transferred[1]) ? (float)$transferred[1] : 0;
    $rowDate = EndOfTheMonth($month);
    if ($rowDate > $endDate)
    {
      $rowDate = clone $endDate;
    }

    if (($flow == 'bank' || $flow == 'both') && $bankAmount != 0)
    {
      AddTransferRow($records, $rowDate, $bankAmount, "Bank");
    }
    if (($flow == 'cash' || $flow == 'both') && $cashAmount != 0)
    {
      AddTransferRow($records, $rowDate, $cashAmount, "Készpénz");
    }

    $month = StartOfTheMonth($month->modify("+1 month"));
  }

  usort($records, function($a, $b) {
    $dateA = is_object($a) ? $a->DATETIME : $a['DATETIME'];
    $dateB = is_object($b) ? $b->DATETIME : $b['DATETIME'];
    if ($dateA == $dateB)
    {
      return 0;
    }
    return ($dateA < $dateB) ? -1 : 1;
  });

  return $records;
}

try
{
  $ma_FinalArray = [];
  if ($res = $db->query($mc_sql, $ma_params)) {
    $ma_FinalArray = $res;
  }
  $ma_FinalArray = AddMonthlyTransferRowsToConference($ma_FinalArray, $md_1stDay, $md_lastSecond, $mn_ChurchId, $db, $mc_Flow);
  
  // Nyitó egyenleg kiszámítása a pénztárban használt OTS logikával.
  $mo_OpeningBankBalance = new TRecord;
  $current_balance = OpeningBalance($md_1stDay, $mn_ChurchId, $mn_BalanceType, true);
  if ($mc_Flow == 'cash')
  {
    $mo_OpeningBankBalance->FLOW = "Készpénz";
  }
  elseif ($mc_Flow == 'both')
  {
    $mo_OpeningBankBalance->FLOW = "Mindkettő";
  }
  else
  {
    $mo_OpeningBankBalance->FLOW = "Bank";
  }
  $mo_OpeningBankBalance->SUMAMOUNT = $current_balance;
  $mo_OpeningBankBalance->DATETIME = $md_1stDay->format("Y-m-d");
  $mo_OpeningBankBalance->balance = $current_balance;
  
  // Futó egyenleg számítása a szerveren
  foreach ($ma_FinalArray as $key => $record) {
      $amt = is_object($record) ? $record->SUMAMOUNT : $record['SUMAMOUNT'];
      $dt = is_object($record) ? $record->DATETIME : $record['DATETIME'];
      $current_balance += (float)$amt;
      if (is_object($record)) {
          $ma_FinalArray[$key]->balance = $current_balance;
          $ma_FinalArray[$key]->DATETIME = $dt ? substr($dt, 0, 10) : '';
      } else {
          $ma_FinalArray[$key]['balance'] = $current_balance;
          $ma_FinalArray[$key]['DATETIME'] = $dt ? substr($dt, 0, 10) : '';
      }
      CleanRecordForExcel($ma_FinalArray[$key]);
  }
  CleanRecordForExcel($mo_OpeningBankBalance);

  if (ob_get_length())
  {
    ob_clean();
  }
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(array_merge([$mo_OpeningBankBalance], $ma_FinalArray), JSON_INVALID_UTF8_SUBSTITUTE);
}
catch (Exception $e)
{  
  error_log("all_transactions_multi_dt.php query failed: " . $e->getMessage());
  SendJsonError(500, "A lekérdezés feldolgozása nem sikerült.");
}
