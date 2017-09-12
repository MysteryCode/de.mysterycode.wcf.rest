<?php

namespace wcf\system\event\listener;

use wcf\system\request\route\ApiRoute;

/**
 * @author	Florian Gail
 * @copyright	2017 Florian Gail <https://www.mysterycode.de>
 * @license	GNU Lesser General Public License <http://www.gnu.org/licenses/lgpl-3.0.txt>
 * @package	de.codequake.wcf.rest
 */
class APIRouteListener implements IParameterizedEventListener {
	/**
	 * @inheritDoc
	 */
	public function execute($eventObj, $className, $eventName, array &$parameters) {
		/** @var $eventObj \wcf\system\request\RouteHandler */
		$route = new ApiRoute();
		$eventObj->addRoute($route);
	}
}
