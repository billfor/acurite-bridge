<?php

require_once 'Net/DNS2.php';

function real_address($address)
{

	$acurite=array();

	$resolver = new Net_DNS2_Resolver( array('nameservers' => array('8.8.8.8')) );

	$resp = $resolver->query("$address.", 'A');

	//print_r($resp);

	foreach ($resp->answer as $addr)
	{
		if ($addr instanceof Net_DNS2_RR_A) $acurite[]=$addr->address;
	}

	return($acurite);
}


?>
