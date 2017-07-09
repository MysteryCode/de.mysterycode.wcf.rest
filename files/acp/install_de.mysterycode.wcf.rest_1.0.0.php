<?php

use wcf\system\WCF;
use wcf\util\StringUtil;

$username = StringUtil::getHash(StringUtil::getRandomID());
$password = StringUtil::getRandomID();
$sql = "UPDATE	wcf".WCF_N."_option
	SET	optionValue = ?
	WHERE	optionName = ?";
$statement = WCF::getDB()->prepareStatement($sql);
$statement->execute([$username, 'api_rest_auth_username']);
$statement->execute([$password, 'api_rest_auth_password']);
