<?php
namespace App;

/**
 * Event Handler main class
 * @package YetiForce.App
 * @license licenses/License.html
 * @author Mariusz Krzaczkowski <m.krzaczkowski@yetiforce.com>
 */
class EventHandler
{

	private static $handlerByType;
	private $recordModel;
	private $moduleName;
	private $params;
	private $userId;

	/**
	 * Get all event handlers
	 * @param boolean $active true/false
	 * @return array
	 */
	public static function getAll($active = true)
	{
		if (Cache::has('EventHandler', 'All')) {
			$handlers = Cache::get('EventHandler', 'All');
		} else {
			$handlers = (new \App\Db\Query())->from('vtiger_eventhandlers')->orderBy(['priority' => SORT_DESC])->all();
			Cache::save('EventHandler', 'All', $handlers);
		}
		if ($active) {
			foreach ($handlers as $key => &$handler) {
				if ($handler['is_active'] !== 1) {
					unset($handlers[$key]);
				}
			}
		}
		return $handlers;
	}

	/**
	 * Get active event handlers by type (event_name)
	 * @param string $name
	 * @return array
	 */
	public static function getByType($name, $moduleName = false)
	{
		if (!isset(static::$handlerByType)) {
			$handlers = [];
			foreach (static::getAll(true) as &$handler) {
				$handlers[$handler['event_name']][] = $handler;
			}
			static::$handlerByType = $handlers;
		}
		$handlers = isset(static::$handlerByType[$name]) ? static::$handlerByType[$name] : [];
		if ($moduleName) {
			foreach ($handlers as $key => &$handler) {
				if ((!empty($handler['include_modules']) && !in_array($moduleName, explode(',', $handler['include_modules']))) || (!empty($handler['exclude_modules']) && in_array($moduleName, explode(',', $handler['exclude_modules'])))) {
					unset($handlers[$key]);
				}
			}
		}
		return $handlers;
	}

	/**
	 * Set record model
	 * @param \App\Vtiger_Record_Model $recordModel
	 */
	public function setRecordModel(\Vtiger_Record_Model $recordModel)
	{
		$this->recordModel = $recordModel;
	}

	/**
	 * Set module name
	 * @param string $moduleName
	 */
	public function setModuleName($moduleName)
	{
		$this->moduleName = $moduleName;
	}

	/**
	 * Set params
	 * @param array $params
	 */
	public function setParams($params)
	{
		$this->params = $params;
	}

	/**
	 * Set user Id
	 * @param int $userId
	 */
	public function setUser($userId)
	{
		$this->userId = $userId;
	}

	/**
	 * Get record model
	 * @return \Vtiger_Record_Model
	 */
	public function getRecordModel()
	{
		return $this->recordModel;
	}

	/**
	 * Get module name
	 * @return string
	 */
	public function getModuleName()
	{
		return $this->moduleName;
	}

	/**
	 * Get params
	 * @return array Additional parameters
	 */
	public function getParams()
	{
		return $this->params;
	}

	/**
	 * Trigger an event
	 * @param string $name Event name
	 * @throws \Exception\AppException
	 */
	public function trigger($name)
	{
		$handlers = static::getByType($name, $this->moduleName);
		foreach ($handlers as &$handler) {
			$handlerInstance = new $handler['handler_class']();
			$function = lcfirst($name);
			if (method_exists($handlerInstance, $function)) {
				$handlerInstance->$function($this);
			} else {
				throw new \Exception\AppException('LBL_HANDLER_NOT_FOUND');
			}
		}
	}

	/**
	 * Set system handler
	 * @param string $name
	 * @return boolean
	 */
	public function setSystemTrigger($name, $class = '', $params = [])
	{
		$handlers = static::getByType($name, $this->moduleName);
		if (empty($handlers)) {
			return false;
		}
		$db = \App\Db::getInstance('admin');
		$isExists = (new \App\Db\Query())->from('s_#__handler_updater')->where(['crmid' => $this->getRecordModel()->getId()])->exists($db);
		if (!$isExists) {
			$db->createCommand()
				->insert('s_#__handler_updater', [
					'tabid' => Module::getModuleId($this->getModuleName()),
					'crmid' => $this->getRecordModel()->getId(),
					'userid' => User::getCurrentUserId(),
					'handler_name' => $name,
					'class' => $class,
					'params' => Json::encode($params)
				])->execute();
		}
	}
}
