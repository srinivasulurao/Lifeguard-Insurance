<?php
/**
 *  Created by: N.Srinivasulu Rao
 *  Date : 30th-May-2016
 *  Send the Chat transcripts.
 * (c) Copyright Oracle Corporation.
 */

error_reporting(E_ALL);
set_time_limit(0); //This going to run the code for unlimited time.
$dateTimeZone = new DateTimeZone("UTC");
$dateTime = new DateTime();
$date= new DateTime(); // Current timezone.
$interval_end =$date->setTimestamp(($date->getTimestamp()+(3600*1)))->format("Y-m-d H:i:s");
//$interval_end=$date->format("Y-m-d H:i:s");
$end_dt = $date->sub(new DateInterval('PT1H'));
$interval_start = $end_dt->format("Y-m-d H:i:s");

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
$adminContactId=79; //Client Email

$contact=RNCPHP\Contact::fetch($adminContactId);
$adminEmail=($contact->Emails[0]->Address)?$contact->Emails[0]->Address:$contact->Emails[1]->Address;

$messageBase= RightNow\Connect\v1_2\MessageBase::fetch(CUSTOM_MSG_CHAT_CRON_SWITCH_VALUE);
$cron_enabled= $messageBase->Value;

$messageBase=RightNow\Connect\v1_2\MessageBase::fetch(CUSTOM_MSG_CHAT_CRON_FILTER_ENABLE);
$filter_enabled=$messageBase->Value;

$analytics_report_id=100009; //Report Id.

$rp_handle=@fopen("/tmp/email_sent.txt","r");
$sent=@fread($rp_handle,filesize("/tmp/email_sent.txt"));
$sent=explode(",",$sent);

$ps_log = new Log(array(
	'type'                  => Type::Import,
	'subtype'               => "Guardian Life LIC Chat Transcript tracking !",
	'logThreshold'          => (DEBUG === true) ? Severity::Debug : Severity::Notice,
	'logToDb'               => true,
	'logToFile'             => false
));

$ps_log->logToFile(false)->logToDb(true)->stdOutputThreshold(Severity::Debug);

if($cron_enabled=="OFF"){
	exit;
}
else{

#########################  Task2  #######################################
##            Lets start the iteration work                            ##
#########################################################################

	try{
		$ps_log->debug("Script Execution Started @ ".$interval_end);
//If it was last executed then no filters would be applied for the Analytics Report.

		if($filter_enabled=="ON") {
			$filters = new RNCPHP\AnalyticsReportSearchFilterArray;
			$filter = new RNCPHP\AnalyticsReportSearchFilter;
			$filter->Name = "EndTimeFilter";
			$filter->Operator = new RightNow\Connect\v1_2\NamedIDOptList();
			$filter->Operator->ID = 9; // this the between operator.
			$filter->Values = array($interval_start, $interval_end);
			$filters[] = $filter;

			$ar= RNCPHP\AnalyticsReport::fetch($analytics_report_id);
			$arr= $ar->run(0,$filters);


		}
		else if($filter_enabled=="OFF"){
			$ar= RNCPHP\AnalyticsReport::fetch($analytics_report_id);
			$arr= $ar->run();
		}
		else{
			//Create my own filter, of just chat id.
			$filters = new RNCPHP\AnalyticsReportSearchFilterArray;
			$filter = new RNCPHP\AnalyticsReportSearchFilter;
			$filter->Name = "ChatId";
			$filter->Operator = new RightNow\Connect\v1_2\NamedIDOptList();
			$filter->Operator->ID = 1; // this the between operator.
			$filter->Values = array(385);
			$filters[] = $filter;

			$ar= RNCPHP\AnalyticsReport::fetch($analytics_report_id);
			$arr= $ar->run(0,$filters);
		}


		//debugger();

		$standard_data_text=<<<xyz
	<?xml version="1.0" encoding="UTF-8"?>

        <interaction>
		<interID>[chat_id]</interID>
		<startTime>[start_time]</startTime> 
		<endTime>[end_time]</endTime>
		<networkID>OSvC</networkID>
		<employeeID>[agent_email]</employeeID>
		<buddyName>[customer_name]</buddyName>
		<transcript>
			<event>

				   <partEntered>
						<networkID>OSvC</networkID>
						<timeStamp>[part_entered]</timeStamp>
						<buddyName>[customer_name]</buddyName>
				   </partEntered>

			</event>

			[conversation]

			<event>

				   <partLeft>
						<networkID>OSvC</networkID>
						<timeStamp>[part_left]</timeStamp>
						<buddyName>[part_left_bn]</buddyName>
				   </partLeft>

			</event>

    </transcript>
</interaction>
xyz;

		$conversations=<<<xyz

	<event>
			<msgSent>
				<timeStamp>[time_stamp]</timeStamp>
				<networkID>OSvC</networkID>
				<buddyName>[from_name]</buddyName>
				<text>[message_text]</text>
			</msgSent>
	</event>

xyz;

		$xmlStoreVar=array();
		$chat_start=array();
		for($i=0;$i<$arr->count();$i++):
			$key=(object)$arr->next();

			if($key->messageText && !in_array($key->chatId,$sent)): // Make sure there are no empty messages.

				$from_name=($key->fromName)?$key->fromName:$key->firstName.$key->lastName; // Either Client's Name of Agent's name would be there.
				$from_name=remove_spcl_chars($from_name);
				$part_left_bn=($key->fromName)?$key->firstName.$key->lastName:remove_spcl_chars(getUserEntityName('Account',$key->agentId));

				$chat_session_data=array(
					'[chat_id]'=>$key->chatId,
					'[start_time]'=>str_to_timestamp($key->startTime),
					'[end_time]'=>str_to_timestamp($key->endTime),
					'[interface_name]'=>$key->interfaceName,
					'[agent_email]'=>$key->agentEmail,
					'[customer_name]'=>remove_spcl_chars($key->firstName.$key->lastName),
					'[part_entered]'=>str_to_timestamp($key->partEntered),
					'[part_left]'=>str_to_timestamp($key->partLeft),
					'[part_left_bn]'=>remove_spcl_chars($part_left_bn)
				);


				$chat_conversation_data=array(
					'[time_stamp]'=>str_to_timestamp($key->messageTimestamp),
					'[from_name]'=>$from_name,
					'[message_text]'=>strip_tags($key->messageText),
					'[message_event]'=>$messageEvent
				);

				$xmlStoreVar[$key->chatId]['html_body']=str_replace(array_keys($chat_session_data),array_values($chat_session_data),$standard_data_text);
				$xmlStoreVar[$key->chatId]['conversation'].=str_replace(array_keys($chat_conversation_data),array_values($chat_conversation_data),$conversations);
			endif;
		endfor;

#########################  Task3  #######################################
##            Now fire the mail                                        ##
#########################################################################

		$mailCounter=1;
		foreach($xmlStoreVar as $key=>$value):
			try{
				$mail_body=$xmlStoreVar[$key]['html_body'];
				$mail_body=str_replace('[conversation]',$xmlStoreVar[$key]['conversation'],$mail_body);
				//create mail message object
				$email = new RNCPHP\Email();
				$email->Address = $adminEmail;
				$email->AddressType->ID = 0;
				$e1="hiralal.dedhia@oracle.com";
				$e2="anirban.chaudhuri@oracle.com";
				$e3="phanikishore_prathapa@glic.com";
				$e4="charles_ashe@glic.com";
				$e5="deepak_purushothaman@glic.com";
				$e6="javier_florez@glic.com";
				$e7="dennis.finn@oracle.com";
				$e8="joseph_pennisi@glic.com";
				$e9="srinivasulu.rao@oracle.com";
				$e10="autonomyjournal@glic.com";

				if($mailCounter==200)
					break;

				$mm = new RNCPHP\MailMessage();
				$mm->To->EmailAddresses=array($e10,$e9);
				//$mm->CC->EmailAddresses = array($email1,$email2,$email3,$email4,$email5,$email6,$email7);
				$mm->Subject = "OSvC ".$key;
				$mm->Body->Text = $mail_body;
				$mm->Options->IncludeOECustomHeaders = true;
				$mm->Headers[0]='X-AUTONOMY-SUBTYPE:OracleChat';
				//$mm->Body->Html = $mail_body;
				$mm->send();
				$sent[]=$key;
				$mailCounter++;
				$ps_log->notice("Chat Transcript Mail Succesfully sent for Chat Id: {$key}"."<br>");

			}
			catch(RNCPHP\ConnectAPIError $err){
				$ps_log->error("Connect PHP Error :".$err->getMessage(). "@". $err->getLine()."<br>");
			}
		endforeach;



	}
	catch(RNCPHP\ConnectAPIError $err){
		$ps_log->error(" Connect PHP Error :".$err->getMessage(). "@". $err->getLine());
	}

}
?>

<?php

$rp_handle=@fopen("/tmp/email_sent.txt","w");
$sent=@fwrite($rp_handle,@implode(",",$sent));

$endMicroTime=microtime(true);
$scriptCompleteTime=$endMicroTime-$startMicroTime;
$scriptCompleteTime=number_format($scriptCompleteTime,2);
$ps_log->debug("Script Execution Completed within ".$scriptCompleteTime." seconds");
echo "Script Execution Completed within ".$scriptCompleteTime." seconds";
?>


<?php
function debug($arrayObject,$height="30px"){
	echo "<textarea style='color:red;height:$height;width:100%'>";
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

function str_to_timestamp($str){
	$str=trim($str,"'");
	$d=new DateTime($str);
	return $d->getTimestamp();
}

function remove_spcl_chars($from_name){

	$from_name=str_replace(" ","",$from_name);
	$from_name=str_replace(".","",$from_name);
	$from_name=str_replace("_","",$from_name);
	$from_name=str_replace(":","",$from_name);
	$from_name=str_replace(";","",$from_name);

	return $from_name;
}

function debugger(){
	global $interval_start, $interval_end, $filters,$arr,$adminEmail;

	debug($interval_start."--".$interval_end."---".$adminEmail);
	debug($filters,"200px");
	$x=array();
	while($red=$arr->next()):
		if($red['messageText'])
			$x[]=$red;
	endwhile;
	debug($x,"600px");
	exit;

}

?>
