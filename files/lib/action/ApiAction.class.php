<?php

namespace wcf\action;

use wcf\data\AbstractDatabaseObjectAction;
use wcf\data\DatabaseObject;
use wcf\data\DatabaseObjectDecorator;
use wcf\data\DatabaseObjectEditor;
use wcf\data\DatabaseObjectList;
use wcf\data\user\User;
use wcf\system\exception\AJAXException;
use wcf\system\request\RouteHandler;
use wcf\util\ArrayUtil;
use wcf\util\JSON;
use wcf\util\StringUtil;

class ApiAction extends AbstractAction {
	/**
	 * @var mixed[]
	 */
	protected $debug = [];
	
	/**
	 * @var mixed[][]
	 */
	protected $blacklist = [];
	
	/**
	 * @var string[]
	 */
	protected $authData = [];
	
	/**
	 * @inheritDoc
	 */
	public function execute() {
		$this->auth();
		
		$this->initBlacklist();
		
		$routeData = RouteHandler::getInstance()->getRouteData();
		if (empty($routeData['api'])) $this->fail();
		$api = $routeData['api'];
		
		switch (strtolower($_SERVER['REQUEST_METHOD'])) {
			case "get":
				$this->get($api);
				break;
			
			default:
				$this->get($api);
				break;
		}
	}
	
	/**
	 * @param mixed[] $api
	 * @throws \wcf\system\exception\AJAXException
	 */
	protected function get($api) {
		if (!class_exists($api['className'])) throw new AJAXException('invalid parameter className' . !empty($api['className']) ? (' "' . $api['className'] . '"') : (''), AJAXException::BAD_PARAMETERS);
		
		$reflectionClass = new \ReflectionClass($api['className']);
		if ($reflectionClass->isAbstract()) {
			throw new AJAXException('class "' . $api['className'] . '" is abstract and can not be used', AJAXException::BAD_PARAMETERS);
		} else if ($reflectionClass->isInterface()) {
			throw new AJAXException('class "' . $api['className'] . '" is an interface and can not be used', AJAXException::BAD_PARAMETERS);
		} else if ($reflectionClass->isTrait()) {
			throw new AJAXException('class "' . $api['className'] . '" is a trait and can not be used', AJAXException::BAD_PARAMETERS);
		}
		
		if ($reflectionClass->isSubclassOf(AbstractDatabaseObjectAction::class)) {
			if (empty($api['id']) && $api['method'] != 'create') throw new AJAXException('parameter id is missing or invalid', AJAXException::MISSING_PARAMETERS);
			if (empty($api['method'])) throw new AJAXException('parameter method is missing or invalid', AJAXException::MISSING_PARAMETERS);
			
			$objectClassName = str_replace('Action', '', $api['className']);
			/** @var \wcf\data\DatabaseObject $object */
			$object = new $objectClassName($api['id']);
			
			$indexColumn = $objectClassName::getDatabaseTableIndexName();
			if ($object === null || !$object->$indexColumn) throw new AJAXException('parameter id is missing or invalid', AJAXException::BAD_PARAMETERS);
			
			$parameters = [];
			if (!empty($_POST['parameters'])) {
				$parameters = @unserialize(StringUtil::trim($_POST['parameters']));
				if (!is_array($parameters)) {
					throw new AJAXException('parameters is no valid serialized array');
				}
				
				$parameters = ArrayUtil::trim($parameters);
			}
			
			/** @var AbstractDatabaseObjectAction $objectAction */
			$objectAction = new $api['className']([$object], $api['method'], $parameters);
			$objectAction->executeAction();
		} else if ($reflectionClass->isSubclassOf(DatabaseObjectEditor::class)) {
			if (empty($api['id']) && $api['method'] != 'create') throw new AJAXException('parameter id is missing or invalid', AJAXException::MISSING_PARAMETERS);
			if (empty($api['method'])) throw new AJAXException('parameter method is missing or invalid', AJAXException::MISSING_PARAMETERS);
			
			$objectClassName = str_replace('Editor', '', $api['className']);
			/** @var \wcf\data\DatabaseObject $object */
			$object = new $objectClassName($api['id']);
			
			$indexColumn = $objectClassName::getDatabaseTableIndexName();
			if ($object === null || !$object->$indexColumn) throw new AJAXException('parameter id is missing or invalid', AJAXException::BAD_PARAMETERS);
			
			$parameters = [];
			if (!empty($_POST['parameters'])) {
				$parameters = @unserialize(StringUtil::trim($_POST['parameters']));
				if (!is_array($parameters)) {
					throw new AJAXException('parameters is no valid serialized array');
				}
				
				$parameters = ArrayUtil::trim($parameters);
			}
			
			/** @var DatabaseObjectEditor $objectEditor */
			$objectEditor = new $api['className']($object);
			$objectEditor->$api['method']($parameters);
		} else {
			if ($api['id']) {
				if ($reflectionClass->isSubclassOf(DatabaseObject::class) && !$reflectionClass->isSubclassOf(DatabaseObjectDecorator::class)) {
					$object = new $api['className']($api['id']);
					
					$indexColumn = $api['className']::getDatabaseTableIndexName();
					if ($object === null || !$object->$indexColumn) throw new AJAXException('parameter id (' . $indexColumn . ') is missing or invalid', AJAXException::BAD_PARAMETERS);
				} else if ($reflectionClass->isSubclassOf(DatabaseObjectDecorator::class)) {
					$baseClass = $api['className']::getBaseClass();
					$object = new $baseClass($api['id']);
					$decoratedObject = new $api['className']($object);
					$this->debug['object'] = $decoratedObject;
				}
			} else {
				$object = new $api['className']();
				
				if (is_subclass_of($object, DatabaseObjectList::class)) {
					$object->readObjects();
				}
			}
			$this->debug['object'] = $object;
			
			if ($api['method']) {
				$method = $api['method'];
				$result = $object->$method();
				
				if (is_array($result)) {
					foreach ($result as $key => $value) {
						if (!empty($this->blacklist[get_class($object)]) && in_array($key, $this->blacklist[get_class($object)])) {
							unset($result[$key]);
							continue;
						}
						foreach ($this->blacklist as $className => $fieldList) {
							if ($reflectionClass->isSubclassOf($className) && in_array($key, $fieldList)) {
								unset($result[$key]);
								break;
							}
						}
					}
				}
				
				$this->debug['methodResult'] = $result;
			}
		}
		
		//GETTED
		
		$output = [];
		
		foreach ($this->debug as $key => $item) {
			if (is_object($item)) {
				$output[$key] = $this->extractProps($this->debug['object']);
			} else {
				$output[$key] = $item;
			}
		}
		
		@header('HTTP/1.1 200 OK');
		echo JSON::encode($output, JSON_PRETTY_PRINT);
		exit;
	}
	
	/**
	 * authenticates against HTTP Basic Auth
	 * @throws \wcf\system\exception\AJAXException
	 */
	protected function auth() {
		$this->loadAuthData();
		
		$user = 'test';
		$password = 'test';
		
		if (empty($this->authData['username'])) throw new AJAXException('username for API-authemtication is missing', AJAXException::MISSING_PARAMETERS);
		if (empty($this->authData['password'])) throw new AJAXException('password for API-authemtication is missing', AJAXException::MISSING_PARAMETERS);
		
		if ($this->authData['username'] != $user) throw new AJAXException('username for API-authentication is invalid', AJAXException::BAD_PARAMETERS);
		if ($this->authData['password'] != $password) throw new AJAXException('password for API-authentication is invalid', AJAXException::BAD_PARAMETERS);
	}
	
	/**
	 * loads auth data
	 */
	protected function loadAuthData() {
		if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) || isset($_SERVER['PHP_AUTH_USER'])) {
			if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) && preg_match('/Basic+(.*)$/i', $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $matches)) {
				list($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']) = explode(':', base64_decode(substr($_SERVER['REDIRECT_HTTP_AUTHORIZATION'], 6)));
			}
			
			if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
				$this->authData['username'] =  StringUtil::trim($_SERVER['PHP_AUTH_USER']);
				$this->authData['password'] =  StringUtil::trim($_SERVER['PHP_AUTH_PW']);
			}
		}
	}
	
	/**
	 * it failed really hard
	 * better use it safe next time.
	 */
	protected function fail() {
		@header('HTTP/1.1 400 Bad Request');
		echo JSON::encode([
			'status' => 'failed',
			'message' => 'no api data provided'
		]);
		exit;
	}
	
	/**
	 * Extracts the properties of an object to an array
	 *
	 * @param $object
	 * @return mixed[]
	 */
	protected function extractProps($object) {
		$public = [];
		
		$reflection = new \ReflectionClass(get_class($object));
		
		foreach ($reflection->getProperties() as $propKey => $property) {
			$property->setAccessible(true);
			
			$value = $property->getValue($object);
			$name = $property->getName();
			
			if (!empty($this->blacklist[get_class($object)]) && in_array($name, $this->blacklist[get_class($object)])) continue;
			$cont = false;
			foreach ($this->blacklist as $className => $fieldList) {
				if ($reflection->isSubclassOf($className) && in_array($name, $fieldList)) {
					$cont = true;
					break;
				}
			}
			if ($cont) continue;
			
			if(is_array($value)) {
				$public[$name] = [];
				
				foreach ($value as $key => $item) {
					if (!empty($this->blacklist[get_class($object)]) && in_array($key, $this->blacklist[get_class($object)])) continue;
					$cont2 = false;
					foreach ($this->blacklist as $className => $fieldList) {
						if ($reflection->isSubclassOf($className) && in_array($key, $fieldList)) {
							$cont2 = true;
							break;
						}
					}
					if ($cont2) continue;
					
					if (is_object($item)) {
						$itemArray = $this->extractProps($item);
						$public[$name][$key] = $itemArray;
					}
					else {
						$public[$name][$key] = $item;
					}
				}
			} else if(is_object($value)) {
				$public[$name] = $this->extractProps($value);
			} else {
				$public[$name] = $value;
			}
		}
		
		return $public;
	}
	
	/**
	 * Initiates the field/info-blacklist
	 */
	protected function initBlacklist() {
		$this->blacklist = [
			User::class => ['password', 'accessToken', 'email'],
			DatabaseObject::class => ['databaseTableName', 'databaseTableIndexIsIdentity', 'databaseTableIndexName', 'databaseTableIndexName', 'sortOrder', 'sortBy']
		];
	}
}
