<?php
/**
 *  Created by: N.Srinivasulu Rao
 *  Date : 30th-May-2016
 *  Send the Chat transcripts.
 * (c) Copyright Oracle Corporation.
 */
error_reporting(E_ALL);
set_time_limit(0); //This going to run the code for unlimited time.

$startMicroTime=microtime(true);
//Display errors i want to see.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

define('DEBUG', true);
define('CUSTOM_SCRIPT', true);
define('PROC_DIR', '/tmp/');


require_once(get_cfg_var('doc_root')."/ConnectPHP/Connect_init.php");
initConnectAPI('srinivasulu','ATG2dfPQ');
require_once (get_cfg_var('doc_root') . '/custom/oracle/libraries/PSLog-2.0.php');


use PS\Log\v2\Log;
use PS\Log\v2\Severity as Severity;
use PS\Log\v2\Type as Type;
use RightNow\Connect\v1_2 as RNCPHP;


########################Task 1###########################################
## First Fetching the settings value, email id & the cron switch value.##
#########################################################################

$messageBase= RightNow\Connect\v1_2\MessageBase::fetch(CUSTOM_MSG_CHAT_TRANSCRIPT_CONTACT_ID);
$adminContactId= $messageBase->Value;
$contact=RNCPHP\Contact::fetch($adminContactId);
$adminEmail=($contact->Emails[0]->Address)?$contact->Emails[0]->Address:$contact->Emails[1]->Address;

$messageBase= RightNow\Connect\v1_2\MessageBase::fetch(CUSTOM_MSG_CHAT_CRON_SWITCH_VALUE);
$cron_enabled= $messageBase->Value;

$messageBase=RightNow\Connect\v1_2\MessageBase::fetch(CUSTOM_MSG_CHAT_CRON_FILTER_ENABLE);
$filter_enabled=$messageBase->Value;

$analytics_report_id=100009; //Some Arbitrary Value.
$current_timestamp=time();
$start_timestamp=$current_timestamp-63600; //Started before one hour
$interval_end=date("Y-m-d H:i:s");
$endTimeObj=new DateTime;
$interval_start=$endTimeObj->setTimestamp($start_timestamp)->format("Y-m-d H:i:s"); // One hour Interval

$ps_log = new Log(array(
	'type'                  => Type::Import,
	'subtype'               => "Guardian Life LIC Chat Transcript tracking !",
	'logThreshold'          => (DEBUG === true) ? Severity::Debug : Severity::Notice,
	'logToDb'               => true,
	'logToFile'             => false
));
$ps_log->logToFile(true)->logToDb(true)->stdOutputThreshold(Severity::Error)->error($message);

if($cron_enabled=="OFF"){
	exit;
}
else{

#########################  Task2  #######################################
##            Lets start the iteration work                            ##
#########################################################################

	try{

//If it was last executed then no filters would be applied for the Analytics Report.

		if($filter_enabled=="ON") {
			$filters = new RNCPHP\AnalyticsReportSearchFilterArray;
			$filter = new RNCPHP\AnalyticsReportSearchFilter;
			$filter->Name = "EndTimeFilter";
			$filter->Operator = new RightNow\Connect\v1_2\NamedIDOptList();
			$filter->Operator->ID = 9; // this the between operator.
			$filter->Values = array($interval_start, $interval_end);
			$filters[] = $filter;
		}
		else{
			//Create my own filter, of just chat id.
			$filters = new RNCPHP\AnalyticsReportSearchFilterArray;
			$filter = new RNCPHP\AnalyticsReportSearchFilter;
			$filter->Name = "ChatId";
			$filter->Operator = new RightNow\Connect\v1_2\NamedIDOptList();
			$filter->Operator->ID = 1; // this the between operator.
			$filter->Values = array(244);
			$filters[] = $filter;
		}



		$ar= RNCPHP\AnalyticsReport::fetch($analytics_report_id);
		$arr= $ar->run(0,$filters);


		$standard_data_text=<<<xyz
	<?xml version="1.0" encoding="UTF-8"?>
        <Transcript>
	<chatId>[chat_id]</chatId>
	<startTime>[start_time]</startTime> 
	<endTime>[end_time]</endTime>
	<interfaceName>[interface_name]</interfaceName>
	<agentId>[agent_id]</agentId>
	<agentName>[agent_name]</agentName>
	<customerId>[customer_id]</customerId>
	<customerName>[customer_name]</customerName>
	<subject>[subject]</subject>
	<groupId>[group_id]</groupId>
	<events>

        [conversation]

        </events>
</Transcript>
xyz;

		$conversations=<<<xyz

	<event>
	<timeStamp>[time_stamp]</timeStamp>
	<fromName>[from_name]</fromName>
	<messageText>[message_text]</messageText>
	</event>

xyz;

		$xmlStoreVar=array();
		for($i=0;$i<$arr->count();$i++):
			$key=(object)$arr->next();
			if($key->messageText):  // Make sure there are no empty messages.
				$chat_session_data=array(
					'[chat_id]'=>$key->chatId,
					'[start_time]'=>$key->startTime,
					'[end_time]'=>$key->endTime,
					'[interface_name]'=>$key->interfaceName,
					'[agent_id]'=>$key->agentId,
					'[agent_name]'=>getUserEntityName('Account',$key->agentId),
					'[customer_id]'=>$key->customerId,
					'[customer_name]'=>getUserEntityName('Contact',$key->customerId),
					'[subject]'=>$key->subject,
					'[group_id]'=>$key->groupId
				);

				$from_name=($key->fromName)?$key->fromName:$key->firstName." ".$key->lastName; // Either Client's Name of Agent's name would be there.
				$chat_conversation_data=array(
					'[time_stamp]'=>$key->messageTimestamp,
					'[from_name]'=>$from_name,
					'[message_text]'=>$key->messageText
				);

				$xmlStoreVar[$key->chatId]['html_body']=str_replace(array_keys($chat_session_data),array_values($chat_session_data),$standard_data_text);
				$xmlStoreVar[$key->chatId]['conversation'].=str_replace(array_keys($chat_conversation_data),array_values($chat_conversation_data),$conversations);
			endif;
		endfor;

#########################  Task3  #######################################
##            Now fire the mail                                        ##
#########################################################################

		foreach($xmlStoreVar as $key=>$value):
			try{
				$mail_body=$xmlStoreVar[$key]['html_body'];
				$mail_body=str_replace('[conversation]',$xmlStoreVar[$key]['conversation'],$mail_body);
				//create mail message object
				$email = new RNCPHP\Email();
				$email->Address = $adminEmail;
				$email->AddressType->ID = 0;
				$email1="hiralal.dedhia@oracle.com";
				$email2="anirban.chaudhuri@oracle.com";
				$email3="phanikishore_prathapa@glic.com";
				$email4="charles_ashe@glic.com";
				$email5="deepak_purushothaman@glic.com";
				$email6="javier_florez@glic.com";
				$email7="dennis.finn@oracle.com";
				$email8="melinda.doane@oracle.com";

				$mm = new RNCPHP\MailMessage();
				//$mm->To->EmailAddresses = array($adminEmail,$email1,$email2,$email3,$email4,$email5,$email6,$email7,$email8);
				$mm->To->EmailAddresses=array($adminEmail);
				$mm->Subject = "OSvC ".$key;
				$mm->Body->Text = $mail_body;
				//$mm->Body->Html = $mail_body;
				$mm->Options->IncludeOECustomHeaders = false;
				$mm->send();
				$ps_log->notice("Chat Transcript Mail Succesfully sent for Chat Id: {$key}");

			}
			catch(RNCPHP\ConnectAPIError $err){
				$ps_log->fatal("Connect PHP Error :".$err->getMessage(). "@". $err->getLine());
			}
		endforeach;

	}
	catch(RNCPHP\ConnectAPIError $err){
		$ps_log->fatal(" Connect PHP Error :".$err->getMessage(). "@". $err->getLine());
	}

}
?>

<?php
function debug($arrayObject){
	echo "<textarea style='color:red;height:600px;width:100%'>";
	print_r($arrayObject);
	echo "</textarea>";
}


function getUserEntityName($type,$user_id){
	if($user_id) {
		if($type=="Contact")
			$user = RNCPHP\Contact::fetch($user_id);
		else
			$user= RNCPHP\Account::fetch($user_id);
		return $user->Name->First." ".$user->Name->Last;
	}
	else
		return "-NA-";

}

?>

<?php
$endMicroTime=microtime(true);
$scriptCompleteTime=$endMicroTime-$startMicroTime;
$scriptCompleteTime=number_format($scriptCompleteTime,2);
$ps_log->debug("Script Execution Completed within ".$scriptCompleteTime." seconds");
echo "Script Execution Completed within ".$scriptCompleteTime." seconds";
?>
