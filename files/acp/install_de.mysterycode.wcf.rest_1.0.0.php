<?php

/**
 * @author	Florian Gail
 * @copyright	2017 Florian Gail <https://www.mysterycode.de>
 * @license	GNU Lesser General Public License <http://www.gnu.org/licenses/lgpl-3.0.txt>
 * @package	de.codequake.wcf.rest
 */

use wcf\system\WCF;
use wcf\util\CryptoUtil;
use wcf\util\exception\CryptoException;
use wcf\util\StringUtil;

$username = StringUtil::getHash(StringUtil::getRandomID()) . '-' . StringUtil::getUUID();
try {
	$password = bin2hex(CryptoUtil::randomBytes(36));
}
catch (CryptoException $e) {
	$password = StringUtil::getUUID();
}

$sql = "UPDATE	wcf".WCF_N."_option
	SET	optionValue = ?
	WHERE	optionName = ?";
$statement = WCF::getDB()->prepareStatement($sql);
$statement->execute([$username, 'api_rest_auth_username']);
$statement->execute([$password, 'api_rest_auth_password']);
