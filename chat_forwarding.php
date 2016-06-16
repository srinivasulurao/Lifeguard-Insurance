<?php
$limit=($_REQUEST['limit'])?$_REQUEST['limit']:1000;
$rp_handle=@fopen("/tmp/email_sent.txt","w");
@fwrite($rp_handle,"");
$limit_loop=@ceil($limit/200);
for($i=1;$i<=$limit_loop;$i++):
    $output.=file_get_contents("http://guardianlife.custhelp.com/cgi-bin/guardianlife.cfg/php/custom/src/oracle/chat_transcript_forward.php");
endfor;

echo $output;
?>