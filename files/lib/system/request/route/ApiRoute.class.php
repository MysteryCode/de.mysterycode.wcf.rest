<?php

namespace wcf\system\request\route;

use wcf\action\ApiAction;
use wcf\util\FileUtil;

class ApiRoute extends DynamicRequestRoute {
	/**
	 * @inheritDoc
	 */
	protected function init() {
		// dummy
	}
	
	/**
	 * @inheritDoc
	 */
	public function matches($requestURL) {
		if (strpos($requestURL, 'api') === false) return;
		
		$requestURL = FileUtil::removeLeadingSlash(FileUtil::removeTrailingSlash($requestURL));
		
		if ($requestURL == 'api') return true;
		
		$pattern = '/
			api\/
			([\D]+)
			(?:\/?
				([\d]+)
				(?:\/
					([^\\/]+)
				)?
				(?:\/
					(.*)
				)?
				\/?
			)?
		/';
		
		//if (preg_match('/api\/([\D]+)(?:\/([\d]+)(?:\/([^\\/]+))?(?:\/(.*))?\/?)/', $requestURL, $components)) {
		if (preg_match(str_replace(["\t", "\n"], '', $pattern), $requestURL, $components)) {
			$this->routeData['api'] = [
				'url' => $components[0],
				'className' => empty($components[1]) ? null : str_replace('/', '\\', FileUtil::removeTrailingSlash($components[1])),
				'id' => empty($components[2]) ? null : $components[2],
				'method' => empty($components[3]) ? null : $components[3],
				'parameters' => empty($components[4]) ? null : $components[4]
			];
			$this->routeData['isDefaultController'] = 0;
			$this->routeData['controller'] = 'api';
			$this->routeData['pageType'] = 'system';
			$this->routeData['className'] = ApiAction::class;
			
			return true;
		}
		
		return false;
	}
	
	/**
	 * @inheritDoc
	 */
	public function canHandle(array $components) {
		// this route cannot build routes, it is a one-way resolver
		return false;
	}
}
