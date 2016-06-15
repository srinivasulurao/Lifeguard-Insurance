<?php
$text1=file_get_contents("http://guardianlife.custhelp.com/cgi-bin/guardianlife.cfg/php/custom/src/oracle/chat_transcript_forward.php");
sleep(3);
$text2=file_get_contents("http://guardianlife.custhelp.com/cgi-bin/guardianlife.cfg/php/custom/src/oracle/chat_transcript_forward.php");

echo $text1;
echo "<hr>";
echo $test2;

?>