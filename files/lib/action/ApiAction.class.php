<?php

namespace wcf\action;

use wcf\data\AbstractDatabaseObjectAction;
use wcf\data\DatabaseObject;
use wcf\data\DatabaseObjectDecorator;
use wcf\data\DatabaseObjectEditor;
use wcf\data\DatabaseObjectList;
use wcf\data\language\Language;
use wcf\data\session\SessionEditor;
use wcf\data\user\User;
use wcf\data\user\UserProfile;
use wcf\system\event\EventHandler;
use wcf\system\exception\AJAXException;
use wcf\system\exception\IllegalLinkException;
use wcf\system\exception\PermissionDeniedException;
use wcf\system\exception\UserInputException;
use wcf\system\request\RouteHandler;
use wcf\system\SingletonFactory;
use wcf\system\user\authentication\DefaultUserAuthentication;
use wcf\system\user\authentication\EmailUserAuthentication;
use wcf\system\user\authentication\IUserAuthentication;
use wcf\system\WCF;
use wcf\util\ArrayUtil;
use wcf\util\CryptoUtil;
use wcf\util\JSON;
use wcf\util\StringUtil;

/**
 * @author	Florian Gail
 * @copyright	2017 Florian Gail <https://www.mysterycode.de>
 * @license	GNU Lesser General Public License <http://www.gnu.org/licenses/lgpl-3.0.txt>
 * @package	de.codequake.wcf.rest
 */
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
	 * @var mixed[][]
	 */
	protected $excludedProps = [];
	
	/**
	 * @var string[]
	 */
	protected $authData = [];
	
	/**
	 * @var \wcf\system\session\SessionHandler
	 */
	protected $initSession = null;
	
	/**
	 * @inheritDoc
	 */
	public function execute() {
		WCF::getSession()->disableTracking();
		
		$this->auth();
		
		$this->initSession = WCF::getSession();
		
		$this->login();
		
		$this->initBlacklist();
		
		$routeData = RouteHandler::getInstance()->getRouteData();
		if (empty($routeData['api'])) $this->fail();
		$api = $routeData['api'];
		
		if (empty($api['method']) && !empty($_REQUEST['method'])) {
			$api['method'] = StringUtil::trim($_REQUEST['method']);
		}
		
		if (empty($api['parameters']) && !empty($_REQUEST['parameters'])) {
			$params = ArrayUtil::trim($_REQUEST['parameters']);
			if (!is_array($_REQUEST['parameters'])) {
				$params = @unserialize($_REQUEST['parameters']);
			}
			
			if (is_array($params)) {
				$api['parameters'] = ArrayUtil::trim($params);
			} else {
				throw new AJAXException('parameters is no valid serialized array');
			}
		}
		
		if (!empty($_REQUEST['objectDecoratorClassName'])) {
			$api['objectDecoratorClassName'] = StringUtil::trim($_REQUEST['objectDecoratorClassName']);
			
			if (!class_exists($api['objectDecoratorClassName'])) {
				throw new AJAXException("parameter objectDecoratorClassName provides an invalid classname", AJAXException::BAD_PARAMETERS);
			}
		}
		
		EventHandler::getInstance()->fireAction($this, 'readApiArray', $api);
		
		//$this->debug['request'] = ArrayUtil::trim($_REQUEST);
		//$this->debug['cookie'] = ArrayUtil::trim($_COOKIE);
		//$this->debug['server'] = ArrayUtil::trim($_SERVER);
		
		switch (strtolower($_SERVER['REQUEST_METHOD'])) {
			case "get":
				$this->get($api);
				break;
			
			default:
				$this->get($api);
				break;
		}
		
		$this->executed();
		
		$output = $this->handleData($this->debug);
		
		$this->beforeEncode($output);
		
		@header('HTTP/1.1 200 OK');
		echo JSON::encode($output, JSON_PRETTY_PRINT);
		exit;
	}
	
	/**
	 * @param mixed[] $api
	 * @throws \wcf\system\exception\AJAXException
	 */
	protected function get($api) {
		if (!class_exists($api['className'])) {
			throw new AJAXException('invalid parameter className' . !empty($api['className']) ? (' "' . $api['className'] . '"') : (''), AJAXException::BAD_PARAMETERS);
		}
		
		$reflectionClass = new \ReflectionClass($api['className']);
		if ($reflectionClass->isAbstract()) {
			throw new AJAXException('class "' . $api['className'] . '" is abstract and can not be used', AJAXException::BAD_PARAMETERS);
		} else if ($reflectionClass->isInterface()) {
			throw new AJAXException('class "' . $api['className'] . '" is an interface and can not be used', AJAXException::BAD_PARAMETERS);
		} else if ($reflectionClass->isTrait()) {
			throw new AJAXException('class "' . $api['className'] . '" is a trait and can not be used', AJAXException::BAD_PARAMETERS);
		}
		
		$method = null;
		if (!empty($api['method'])) {
			try {
				$method = $reflectionClass->getMethod($api['method']);
			}
			catch (\ReflectionException $e) {
				throw new AJAXException($e->getMessage(), AJAXException::BAD_PARAMETERS);
			}
		}
		
		if ($reflectionClass->isSubclassOf(IUserAuthentication::class)) {
			$this->runUserAuthentication($api, $reflectionClass, $method);
		} else if ($reflectionClass->isSubclassOf(AbstractDatabaseObjectAction::class)) {
			$this->runAbstractDatabaseObjectAction($api, $reflectionClass, $method);
		} else if ($reflectionClass->isSubclassOf(DatabaseObjectEditor::class)) {
			$this->runDatabaseObjectEditor($api, $reflectionClass, $method);
		} else if ($reflectionClass->isSubclassOf(DatabaseObjectDecorator::class)) {
			$this->runDatabaseObjectDecorator($api, $reflectionClass, $method);
		} else if ($reflectionClass->isSubclassOf(DatabaseObjectList::class)) {
			$this->runDatabaseObjectList($api, $reflectionClass, $method);
		} else if ($reflectionClass->isSubclassOf(SingletonFactory::class)) {
			$this->runSingletonFactory($api, $reflectionClass, $method);
		} else if ($method !== null && $method->isStatic()) {
			$this->runStaticMethod($api, $reflectionClass, $method);
		} else if ($method !== null && $method->isAbstract()) {
			throw new AJAXException('class "' . $api['className'] . '::' . $method->getName() . '()" is abstract and can not be used', AJAXException::BAD_PARAMETERS);
		} else if ($method !== null && $method->isFinal()) {
			throw new AJAXException('class "' . $api['className'] . '::' . $method->getName() . '()" is final and can not be used', AJAXException::BAD_PARAMETERS);
		} else {
			$this->runDefault($api, $reflectionClass, $method);
		}
		
		$this->beforeOutput($api, $reflectionClass, $method);
	}
	
	/**
	 * @param mixed[]           $api
	 * @param \ReflectionClass  $reflectionClass
	 * @param \ReflectionMethod $method
	 *
	 * @throws \wcf\system\exception\AJAXException
	 */
	protected function runAbstractDatabaseObjectAction(array $api, \ReflectionClass $reflectionClass, $method = null) {
		if (empty($api['id']) && $api['method'] != 'create') throw new AJAXException('parameter id is missing or invalid', AJAXException::MISSING_PARAMETERS);
		if (empty($api['method'])) throw new AJAXException('parameter method is missing or invalid', AJAXException::MISSING_PARAMETERS);
		if (empty($api['parameters'])) throw new AJAXException('parameters array is missing', AJAXException::MISSING_PARAMETERS);
		
		$objectIDs = [];
		$object = null;
		if (!empty($api['id'])) {
			$objectIDs[] = $api['id'];
		} else if (!empty($_REQUEST['objectIDs'])) {
			$objectIDs = ArrayUtil::trim($_REQUEST['objectIDs']);
			if (!is_array($_REQUEST['objectIDs'])) {
				$objectIDs = @unserialize($_REQUEST['objectIDs']);
				
				if (is_array($objectIDs)) {
					$objectIDs = ArrayUtil::trim($objectIDs);
				} else {
					throw new AJAXException('objectIDs is no valid serialized array');
				}
			}
		}
		
		/** @var AbstractDatabaseObjectAction $objectAction */
		$objectAction = new $api['className']($objectIDs, $method->getName(), $api['parameters']);
		try {
			$objectAction->validateAction();
			$object = $objectAction->executeAction()['returnValues'];
		}
		catch (PermissionDeniedException $e) {
			throw new AJAXException("could not execute " . $method->getName(), AJAXException::INSUFFICIENT_PERMISSIONS);
		}
		catch (UserInputException $e) {
			if ($e->getType() == 'empty') {
				throw new AJAXException("could not execute " . $method->getName(), AJAXException::MISSING_PARAMETERS);
			}
			else {
				throw new AJAXException("could not execute " . $method->getName(), AJAXException::BAD_PARAMETERS);
			}
		}
		catch (IllegalLinkException $e) {
			throw new AJAXException("could not execute " . $method->getName(), AJAXException::ILLEGAL_LINK);
		}
		catch (\SystemException $e) {
			throw new AJAXException("could not execute " . $method->getName(), AJAXException::BAD_PARAMETERS);
		}
		catch (\Exception $e) {
			throw new AJAXException("could not execute " . $method->getName(), AJAXException::INTERNAL_ERROR);
		}
		
		$this->debug['object'] = $object;
		$this->debug['objectAction'] = $objectAction;
	}
	
	/**
	 * @param mixed[]           $api
	 * @param \ReflectionClass  $reflectionClass
	 * @param \ReflectionMethod $method
	 *
	 * @throws \wcf\system\exception\AJAXException
	 */
	protected function runDatabaseObjectEditor(array $api, \ReflectionClass $reflectionClass, $method = null) {
		if (empty($api['id']) && $api['method'] != 'create') throw new AJAXException('parameter id is missing or invalid', AJAXException::MISSING_PARAMETERS);
		if (empty($api['method'])) throw new AJAXException('parameter method is missing or invalid', AJAXException::MISSING_PARAMETERS);
		
		$objectClassName = str_replace('Editor', '', $api['className']);
		/** @var \wcf\data\DatabaseObject $object */
		$object = new $objectClassName($api['id']);
		
		$indexColumn = $objectClassName::getDatabaseTableIndexName();
		if ($object === null || !$object->$indexColumn) throw new AJAXException('parameter id is missing or invalid', AJAXException::BAD_PARAMETERS);
		
		if (empty($api['parameters'])) {
			throw new AJAXException('parameters array is missing', AJAXException::MISSING_PARAMETERS);
		}
		
		/** @var DatabaseObjectEditor $objectEditor */
		$objectEditor = new $api['className']($object);
		
		$params = [];
		foreach ($method->getParameters() as $parameter) {
			if (!empty($api['parameters'][$parameter->getName()])) {
				$params[] = $api['parameters'][$parameter->getName()];
			} else if (!$parameter->canBePassedByValue()) {
				throw new AJAXException('parameter ' . $parameter->getName() . ' is missing', AJAXException::MISSING_PARAMETERS);
			}
		}
		
		if (count($params) == 1) $result = call_user_func([$objectEditor, $api['method']], $params[0]);
		else $result = call_user_func_array([$objectEditor, $api['method']], $params);
		
		$this->debug['object'] = $object;
		$this->debug['result'] = $result;
		$this->debug['objectEditor'] = $objectEditor;
	}
	
	/**
	 * @param mixed[]           $api
	 * @param \ReflectionClass  $reflectionClass
	 * @param \ReflectionMethod $method
	 *
	 * @throws \wcf\system\exception\AJAXException
	 */
	protected function runDatabaseObjectDecorator(array $api, \ReflectionClass $reflectionClass, $method = null) {
		$baseClass = $api['className']::getBaseClass();
		$object = new $baseClass($api['id']);
		
		/** @var DatabaseObjectDecorator $decoratedObject */
		$decoratedObject = new $api['className']($object);
		
		$this->debug['object'] = $decoratedObject;
		$this->debug['decoratedObject'] = $object;
	}
	
	/**
	 * @param mixed[]           $api
	 * @param \ReflectionClass  $reflectionClass
	 * @param \ReflectionMethod $method
	 *
	 * @throws \wcf\system\exception\AJAXException
	 */
	protected function runDatabaseObjectList(array $api, \ReflectionClass $reflectionClass, $method = null) {
		/** @var DatabaseObjectList $object */
		$object = new $api['className']();
		$object->readObjects();
		
		$this->debug['object'] = $object;
	}
	
	/**
	 * @param mixed[]           $api
	 * @param \ReflectionClass  $reflectionClass
	 * @param \ReflectionMethod $method
	 *
	 * @throws \wcf\system\exception\AJAXException
	 */
	public function runUserAuthentication(array $api, \ReflectionClass $reflectionClass, $method = null) {
		$this->runSingletonFactory($api, $reflectionClass, $method);
		
		if ($method !== null && $method->getName() == 'loginManually') {
			$this->setUserReturnValues($this->debug['object']);
		}
	}
	
	/**
	 * @param mixed[]           $api
	 * @param \ReflectionClass  $reflectionClass
	 * @param \ReflectionMethod $method
	 *
	 * @throws \wcf\system\exception\AJAXException
	 */
	protected function runSingletonFactory(array $api, \ReflectionClass $reflectionClass, $method = null) {
		$params = [];
		
		foreach ($method->getParameters() as $parameter) {
			if (!empty($api['parameters'][$parameter->getName()])) {
				$params[] = $api['parameters'][$parameter->getName()];
			} else if (!$parameter->canBePassedByValue()) {
				throw new AJAXException('parameter ' . $parameter->getName() . ' is missing', AJAXException::MISSING_PARAMETERS);
			}
		}
		
		if (count($params) == 1) $object = call_user_func([$api['className']::getInstance(), $api['method']], $params[0]);
		else $object = call_user_func_array([$api['className']::getInstance(), $api['method']], $params);
		
		$this->debug['object'] = $object;
	}
	
	/**
	 * @param mixed[]           $api
	 * @param \ReflectionClass  $reflectionClass
	 * @param \ReflectionMethod $method
	 *
	 * @throws \wcf\system\exception\AJAXException
	 */
	protected function runStaticMethod(array $api, \ReflectionClass $reflectionClass, $method = null) {
		$params = [];
		
		foreach ($method->getParameters() as $parameter) {
			if (!empty($api['parameters'][$parameter->getName()])) {
				$params[] = $api['parameters'][$parameter->getName()];
			} else if (!$parameter->canBePassedByValue()) {
				throw new AJAXException('parameter ' . $parameter->getName() . ' is missing', AJAXException::MISSING_PARAMETERS);
			}
		}
		
		if (count($params) == 1) $object = call_user_func([$api['className'], $api['method']], $params[0]);
		else $object = call_user_func_array([$api['className'], $api['method']], $params);
		
		$this->debug['object'] = $object;
	}
	
	/**
	 * @param mixed[]           $api
	 * @param \ReflectionClass  $reflectionClass
	 * @param \ReflectionMethod $method
	 *
	 * @throws \wcf\system\exception\AJAXException
	 */
	protected function runDefault(array $api, \ReflectionClass $reflectionClass, $method = null) {
		if ($reflectionClass->isSubclassOf(DatabaseObject::class) && !$reflectionClass->isSubclassOf(DatabaseObjectDecorator::class)) {
			$object = new $api['className']($api['id']);
			
			$indexColumn = $api['className']::getDatabaseTableIndexName();
			if ($object === null || !$object->$indexColumn) throw new AJAXException('parameter id (' . $indexColumn . ') is missing or invalid', AJAXException::BAD_PARAMETERS);
		} else if ($reflectionClass->isSubclassOf(DatabaseObjectDecorator::class)) {
			$this->runDatabaseObjectDecorator($api, $reflectionClass, $method);
			return;
		} else {
			$object = new $api['className']();
		}
		
		if ($method !== null) {
			$params = [];
			
			foreach ($method->getParameters() as $parameter) {
				if (!empty($api['parameters'][$parameter->getName()])) {
					$params[] = $api['parameters'][$parameter->getName()];
				} else if (!$parameter->canBePassedByValue()) {
					throw new AJAXException('parameter ' . $parameter->getName() . ' is missing', AJAXException::MISSING_PARAMETERS);
				}
			}
			
			$result = call_user_func_array([$object, $method->getName()], $params);
			
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
		
		$this->debug['object'] = $object;
	}
	
	/**
	 * Some sneaky stiff before the results are sent
	 *
	 * @param mixed[]           $api
	 * @param \ReflectionClass  $reflectionClass
	 * @param \ReflectionMethod $method
	 *
	 * @throws \wcf\system\exception\AJAXException
	 */
	protected function beforeOutput(array $api, \ReflectionClass $reflectionClass, $method = null) {
		if (!empty($api['objectDecoratorClassName'])) {
			try {
				$decoratedObject = new $api['objectDecoratorClassName']($this->debug['object']);
				$this->debug['object'] = $decoratedObject;
			}
			catch (\Exception $e) {
				throw new AJAXException("decoration of object to " . $api['objectDecoratorClassName'] . "failed", AJAXException::BAD_PARAMETERS);
			}
		}
		
		if (!empty($this->debug['object']) && $this->debug['object'] instanceof UserProfile) {
			$this->debug['object']->getAvatar();
		}
		
		if (!empty($this->debug['object']) && $this->debug['object'] instanceof User) {
			$this->debug['object']->hasAdministrativeAccess();
			$this->debug['object']->getTimeZone();
			$this->debug['object']->getLanguageIDs();
			$this->debug['object']->getGroupIDs();
		}
		
		EventHandler::getInstance()->fireAction($this, 'beforeOutput', $this->debug);
	}
	
	/**
	 * Some sneaky changes before output arry get's parsed to JSON
	 * @param array $output
	 */
	protected function beforeEncode(array &$output) {
		if (!empty($this->debug['object']) && $this->debug['object'] instanceof UserProfile) {
			/** @var \wcf\data\user\avatar\IUserAvatar $avatar */
			$avatar = $this->debug['object']->getAvatar();
			
			if (!empty($output['object'])) {
				$output['object']['api_avatarDownloadURL'] = $avatar->getURL();
			}
		}
		
		EventHandler::getInstance()->fireAction($this, 'beforeEncode', $output);
	}
	
	/**
	 * Loggs a user in - or tries it
	 */
	protected function login() {
		$skip = false;
		$user = null;
		
		if (!empty($_REQUEST['login']['sessionID']) && !empty($_REQUEST['login']['securityToken'])) {
			WCF::getSession()->delete();
			WCF::getSession()->load(SessionEditor::class, StringUtil::trim($_REQUEST['login']['sessionID']));
			if (!WCF::getSession()->checkSecurityToken(StringUtil::trim($_REQUEST['login']['securityToken']))) {
				WCF::getSession()->initSession();
			} else {
				$skip = true;
				$this->setUserReturnValues($user, true);
			}
		}
		
		if (!$skip && !empty($_REQUEST['login']['username']) && !empty($_REQUEST['login']['password'])) {
			$user = DefaultUserAuthentication::getInstance()->loginManually(StringUtil::trim($_REQUEST['login']['username']), StringUtil::trim($_REQUEST['login']['password']));
			if ($user !== null && $user->userID) {
				$this->setUserReturnValues($user);
			} else {
				$user = null;
			}
		} else if (!$skip && !empty($_REQUEST['login']['email']) && !empty($_REQUEST['login']['password'])) {
			$user = EmailUserAuthentication::getInstance()->loginManually(StringUtil::trim($_REQUEST['login']['email']), StringUtil::trim($_REQUEST['login']['password']));
			if ($user !== null && $user->userID) {
				$this->setUserReturnValues($user);
			} else {
				$user = null;
			}
		}
	}
	
	/**
	 * Sets the user return values
	 *
	 * @param User $user
	 * @param boolean $skipSessionChange
	 */
	protected function setUserReturnValues($user, $skipSessionChange = false) {
		if ($user == null || !$user->userID) {
			return;
		}
		
		if (!$skipSessionChange) {
			WCF::getSession()->changeUser($user);
		}
		
		// user object
		//$this->debug['user'] = $user;
		
		// user data
		$this->debug['username'] = $user->getUsername();
		$this->debug['groupIDs'] = $user->getGroupIDs();
		$this->debug['languageIDs'] = $user->getLanguageIDs();
		$this->debug['language'] = $user->getLanguage();
		$this->debug['hasAdministrativeAccess'] = $user->hasAdministrativeAccess();
		$this->debug['banned'] = $user->banned;
		
		// session data
		$this->debug['languageID'] = WCF::getSession()->getLanguageID();
		$this->debug['sessionID'] = WCF::getSession()->sessionID;
		$this->debug['securityToken'] = WCF::getSession()->getSecurityToken();
		//$this->debug['sessionCookie'] = HeaderUtil::setCookie()
	}
	
	/**
	 * Authenticates the request
	 */
	protected function auth() {
		$this->loadAuthData();
		
		$user = API_REST_AUTH_USERNAME;
		$password = API_REST_AUTH_PASSWORD;
		
		if (empty($this->authData['username'])) throw new AJAXException('username for API-authemtication is missing', AJAXException::MISSING_PARAMETERS);
		if (empty($this->authData['password'])) throw new AJAXException('password for API-authemtication is missing', AJAXException::MISSING_PARAMETERS);
		
		if (!CryptoUtil::secureCompare($this->authData['username'], $user)) throw new AJAXException('username for API-authentication is invalid', AJAXException::BAD_PARAMETERS);
		if (!CryptoUtil::secureCompare($this->authData['password'], $password)) throw new AJAXException('password for API-authentication is invalid', AJAXException::BAD_PARAMETERS);
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
	 * Get's data ready for export
	 *
	 * @param mixed[] $input
	 * @return mixed[]
	 */
	protected function handleData(array $input) {
		$output = [];
		
		foreach ($input as $key => $item) {
			if (is_object($item)) {
				$output[$key] = $this->extractProps($item);
			} else if(is_array($item)) {
				$output[$key] = $this->handleData($item);
			} else {
				$output[$key] = $item;
			}
		}
		
		return $output;
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
		
		if ($reflection->isSubclassOf(User::class) || $reflection->getName() == User::class) {
			/** @var User $object */
			$object->hasAdministrativeAccess();
			$object->getTimeZone();
			$object->getLanguageIDs();
			$object->getGroupIDs();
			$reflection = new \ReflectionClass(get_class($object));
		}
		
		foreach ($reflection->getProperties() as $propKey => $property) {
			$property->setAccessible(true);
			
			$value = $property->getValue($object);
			$name = $property->getName();
			
			if (!empty($this->excludedProps[$reflection->getName()])) {
				if (in_array($name, $this->excludedProps[$reflection->getName()])) {
					continue;
				}
			}
			
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
			User::class => ['password', 'accessToken'],
			DatabaseObject::class => ['databaseTableName', 'databaseTableIndexIsIdentity', 'databaseTableIndexName', 'databaseTableIndexName', 'sortOrder', 'sortBy']
		];
		
		$this->excludedProps = [
			User::class => ['userOptions'],
			Language::class => ['items', 'dynamicItems']
		];
		
		EventHandler::getInstance()->fireAction($this, 'initBlacklist', $this->blacklist);
	}
	
	/**
	 * Debug output without html stuff
	 * @see \wcfDebug()
	 */
	public function debug() {
		$args = func_get_args();
		$length = count($args);
		if ($length === 0) {
			echo "ERROR: No arguments provided.";
		}
		else {
			for ($i = 0; $i < $length; $i++) {
				$arg = $args[$i];
				
				echo "Argument {$i} (" . gettype($arg) . ")";
				
				if (is_array($arg) || is_object($arg)) {
					print_r($arg);
				}
				else {
					var_dump($arg);
				}
			}
		}
		
		$backtrace = debug_backtrace();
		
		// output call location to help finding these debug outputs again
		echo "wcfDebug() called in {$backtrace[0]['file']} on line {$backtrace[0]['line']}";
		exit;
	}
}
