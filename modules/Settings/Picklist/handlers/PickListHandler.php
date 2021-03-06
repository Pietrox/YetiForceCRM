<?php

class PickListHandler extends VTEventHandler
{

	public function handleEvent($eventName, $entityData)
	{
		$adb = PearDatabase::getInstance();
		

		if ($eventName == 'vtiger.picklist.afterrename') {
			$this->operationsAfterPicklistRename($entityData);
		} elseif ($eventName == 'vtiger.picklist.afterdelete') {
			$this->operationsAfterPicklistDelete($entityData);
		}
	}

	/**
	 * Function to perform operation after picklist rename
	 * @param type $entityData
	 */
	public function operationsAfterPicklistRename($entityData)
	{

		$db = PearDatabase::getInstance();
		$adb = App\Db::getInstance();
		$pickListFieldName = $entityData['fieldname'];
		$oldValue = $entityData['oldvalue'];
		$newValue = $entityData['newvalue'];
		$moduleName = $entityData['module'];

		$moduleModel = Vtiger_Module_Model::getInstance($moduleName);
		$tabId = $moduleModel->getId();
		//update picklist dependency values 
		$query = "SELECT id,targetvalues FROM vtiger_picklist_dependency where targetfield=? and tabid=?";
		$result = $db->pquery($query, array($pickListFieldName, $tabId));
		$num_rows = $db->num_rows($result);
		for ($i = 0; $i < $num_rows; $i++) {
			$row = $db->query_result_rowdata($result, $i);
			$value = decode_html($row['targetvalues']);
			$explodedValueArray = \App\Json::decode($value);
			$arrayKey = array_search($oldValue, $explodedValueArray);
			if ($arrayKey !== false) {
				$explodedValueArray[$arrayKey] = $newValue;
			}
			$value = \App\Json::encode($explodedValueArray);
			$query = 'UPDATE vtiger_picklist_dependency SET targetvalues=? where id=? && tabid=?';
			$db->pquery($query, array($value, $row['id'], $tabId));
		}
		$fieldModel = Vtiger_Field_Model::getInstance($pickListFieldName, $moduleModel);
		$advFiltercolumnName = $fieldModel->getCustomViewColumnName();
		$reportFilterColumnName = $fieldModel->getReportFilterColumnName();

		//update advancefilter values
		$query = 'SELECT cvid,value,columnindex,groupid FROM vtiger_cvadvfilter where columnname=?';
		$result = $db->pquery($query, array($advFiltercolumnName));
		$num_rows = $db->num_rows($result);
		for ($i = 0; $i < $num_rows; $i++) {
			$row = $db->query_result_rowdata($result, $i);
			$value = $row['value'];
			$explodedValueArray = explode(',', $value);
			$arrayKey = array_search($oldValue, $explodedValueArray);
			if ($arrayKey !== false) {
				$explodedValueArray[$arrayKey] = $newValue;
			}
			$value = implode(',', $explodedValueArray);
			$query = 'UPDATE vtiger_cvadvfilter SET value=? where columnname=? and cvid=? and columnindex=? and groupid=?';
			$db->pquery($query, array($value, $advFiltercolumnName, $row['cvid'], $row['columnindex'], $row['groupid']));
		}

		//update reportsFilter values
		$query = 'SELECT queryid,value,columnindex,groupid FROM vtiger_relcriteria where columnname=?';
		$result = $db->pquery($query, array($reportFilterColumnName));
		$num_rows = $db->num_rows($result);
		for ($i = 0; $i < $num_rows; $i++) {
			$row = $db->query_result_rowdata($result, $i);
			$value = $row['value'];
			$explodedValueArray = explode(',', $value);
			$arrayKey = array_search($oldValue, $explodedValueArray);
			if ($arrayKey !== false) {
				$explodedValueArray[$arrayKey] = $newValue;
			}
			$value = implode(',', $explodedValueArray);
			$query = 'UPDATE vtiger_relcriteria SET value=? where columnname=? and queryid=? and columnindex=? and groupid=?';
			$db->pquery($query, array($value, $reportFilterColumnName, $row['queryid'], $row['columnindex'], $row['groupid']));
		}

		//update Workflows values
		$dataReader = (new \App\Db\Query())->select(['workflow_id', 'test'])->from('com_vtiger_workflows')->where([
			'and',
			['module_name' => $moduleName],
			['<>', 'test', ''],
			['not', ['test' => null]],
			['<>', 'test', 'null'],
			['like', 'test', $oldValue]
		])->createCommand()->query();

		while ($row = $dataReader->read()) {
			$condition = decode_html($row['test']);
			$decodedArrayConditions = \App\Json::decode($condition);
			if (!empty($decodedArrayConditions)) {
				foreach ($decodedArrayConditions as $key => $condition) {
					if ($condition['fieldname'] == $pickListFieldName) {
						$value = $condition['value'];
						$explodedValueArray = explode(',', $value);
						$arrayKey = array_search($oldValue, $explodedValueArray);
						if ($arrayKey !== false) {
							$explodedValueArray[$arrayKey] = $newValue;
						}
						$value = implode(',', $explodedValueArray);
						$condition['value'] = $value;
					}
					$decodedArrayConditions[$key] = $condition;
				}
				$condtion = \App\Json::encode($decodedArrayConditions);
				$adb->createCommand()->update('com_vtiger_workflows', ['test' => $condtion], ['workflow_id' => $row['workflow_id']])
						->execute();
			}
		}

		//update workflow task
		$query = 'SELECT task,task_id,workflow_id FROM com_vtiger_workflowtasks where task LIKE ?';
		$result = $db->pquery($query, ["%$oldValue%"]);
		$num_rows = $db->num_rows($result);

		for ($i = 0; $i < $num_rows; $i++) {
			$row = $db->raw_query_result_rowdata($result, $i);
			$task = $row['task'];
			$taskComponents = explode(':', $task);
			$classNameWithDoubleQuotes = $taskComponents[2];
			$className = str_replace('"', '', $classNameWithDoubleQuotes);
			require_once("modules/com_vtiger_workflow/VTTaskManager.php");
			require_once 'modules/com_vtiger_workflow/tasks/' . $className . '.php';
			$unserializeTask = unserialize($task);
			if (array_key_exists("field_value_mapping", $unserializeTask)) {
				$fieldMapping = \App\Json::decode($unserializeTask->field_value_mapping);
				if (!empty($fieldMapping)) {
					foreach ($fieldMapping as $key => $condition) {
						if ($condition['fieldname'] == $pickListFieldName) {
							$value = $condition['value'];
							$explodedValueArray = explode(',', $value);
							$arrayKey = array_search($oldValue, $explodedValueArray);
							if ($arrayKey !== false) {
								$explodedValueArray[$arrayKey] = $newValue;
							}
							$value = implode(',', $explodedValueArray);
							$condition['value'] = $value;
						}
						$fieldMapping[$key] = $condition;
					}
					$updatedTask = \App\Json::encode($fieldMapping);
					$unserializeTask->field_value_mapping = $updatedTask;
					$serializeTask = serialize($unserializeTask);
					$query = 'UPDATE com_vtiger_workflowtasks SET task=? where workflow_id=? && task_id=?';
					$db->pquery($query, array($serializeTask, $row['workflow_id'], $row['task_id']));
				}
			} else {
				if ($className == 'VTCreateEventTask') {
					if ($pickListFieldName == 'activitystatus') {
						$pickListFieldName = 'status';
					} elseif ($pickListFieldName == 'activitytype') {
						$pickListFieldName = 'eventType';
					}
				} elseif ($className == 'VTCreateTodoTask') {
					if ($pickListFieldName == 'activitystatus') {
						$pickListFieldName = 'status';
					} elseif ($pickListFieldName == 'taskpriority') {
						$pickListFieldName = 'priority';
					}
				}
				if (array_key_exists($pickListFieldName, $unserializeTask)) {
					$value = $unserializeTask->$pickListFieldName;
					$explodedValueArray = explode(',', $value);
					$arrayKey = array_search($oldValue, $explodedValueArray);
					if ($arrayKey !== false) {
						$explodedValueArray[$arrayKey] = $newValue;
					}
					$value = implode(',', $explodedValueArray);
					$unserializeTask->$pickListFieldName = $value;
					$serializeTask = serialize($unserializeTask);
					$query = 'UPDATE com_vtiger_workflowtasks SET task=? where workflow_id=? && task_id=?';
					$db->pquery($query, array($serializeTask, $row['workflow_id'], $row['task_id']));
				}
			}
		}
	}

	/**
	 * Function to perform operation after picklist delete
	 * @param type $entityData
	 */
	public function operationsAfterPicklistDelete($entityData)
	{
		$db = PearDatabase::getInstance();
		$pickListFieldName = $entityData['fieldname'];
		$valueToDelete = $entityData['valuetodelete'];
		$replaceValue = $entityData['replacevalue'];
		$moduleName = $entityData['module'];

		$moduleModel = Vtiger_Module_Model::getInstance($moduleName);
		$fieldModel = Vtiger_Field_Model::getInstance($pickListFieldName, $moduleModel);
		$advFiltercolumnName = $fieldModel->getCustomViewColumnName();
		$reportFilterColumnName = $fieldModel->getReportFilterColumnName();

		//update advancefilter values
		$query = 'SELECT cvid,value,columnindex,groupid FROM vtiger_cvadvfilter where columnname=?';
		$result = $db->pquery($query, array($advFiltercolumnName));
		$num_rows = $db->num_rows($result);
		for ($i = 0; $i < $num_rows; $i++) {
			$row = $db->query_result_rowdata($result, $i);
			$value = $row['value'];
			$explodedValueArray = explode(',', $value);
			foreach ($valueToDelete as $value) {
				$arrayKey = array_search($value, $explodedValueArray);
				if ($arrayKey !== false) {
					$explodedValueArray[$arrayKey] = $replaceValue;
				}
			}
			$value = implode(',', $explodedValueArray);
			$query = 'UPDATE vtiger_cvadvfilter SET value=? where columnname=? and cvid=? and columnindex=? and groupid=?';
			$db->pquery($query, array($value, $advFiltercolumnName, $row['cvid'], $row['columnindex'], $row['groupid']));
		}

		//update reportsFilter values
		$query = 'SELECT queryid,value,columnindex,groupid FROM vtiger_relcriteria where columnname=?';
		$result = $db->pquery($query, array($reportFilterColumnName));
		$num_rows = $db->num_rows($result);
		for ($i = 0; $i < $num_rows; $i++) {
			$row = $db->query_result_rowdata($result, $i);
			$value = $row['value'];
			$explodedValueArray = explode(',', $value);
			foreach ($valueToDelete as $value) {
				$arrayKey = array_search($value, $explodedValueArray);
				if ($arrayKey !== false) {
					$explodedValueArray[$arrayKey] = $replaceValue;
				}
			}
			$value = implode(',', $explodedValueArray);
			$query = 'UPDATE vtiger_relcriteria SET value=? where columnname=? and queryid=? and columnindex=? and groupid=?';
			$db->pquery($query, array($value, $reportFilterColumnName, $row['queryid'], $row['columnindex'], $row['groupid']));
		}


		foreach ($valueToDelete as $value) {
			//update Workflows values
			$dataReader = (new \App\Db\Query())->select(['workflow_id', 'test'])->from('com_vtiger_workflows')->where([
				'and',
				['module_name' => $moduleName],
				['<>', 'test', ''],
				['not', ['test' => null]],
				['<>', 'test', 'null'],
				['like', 'test', $value]
			])->createCommand()->query();
			while ($row = $dataReader->read()) {
				$condition = decode_html($row['test']);
				$decodedArrayConditions = \App\Json::decode($condition);
				if (!empty($decodedArrayConditions)) {
					foreach ($decodedArrayConditions as $key => $condition) {
						if ($condition['fieldname'] == $pickListFieldName) {
							$value = $condition['value'];
							$explodedValueArray = explode(',', $value);
							foreach ($valueToDelete as $value) {
								$arrayKey = array_search($value, $explodedValueArray);
								if ($arrayKey !== false) {
									$explodedValueArray[$arrayKey] = $replaceValue;
								}
							}
							$value = implode(',', $explodedValueArray);
							$condition['value'] = $value;
						}
						$decodedArrayConditions[$key] = $condition;
					}
					$condtion = \App\Json::encode($decodedArrayConditions);
					$query = 'UPDATE com_vtiger_workflows SET test=? where workflow_id=?';
					$db->pquery($query, array($condtion, $row['workflow_id']));
				}
			}
		}


		foreach ($valueToDelete as $value) {
			//update workflow task
			$dataReader = (new \App\Db\Query())->select(['task', 'workflow_id', 'task_id'])->from('com_vtiger_workflowtasks')->where(['like', 'task', $value])
					->createCommand()->query();
			while ($row = $dataReader->read()) {
				$task = $row['task'];
				$taskComponents = explode(':', $task);
				$classNameWithDoubleQuotes = $taskComponents[2];
				$className = str_replace('"', '', $classNameWithDoubleQuotes);
				require_once("modules/com_vtiger_workflow/VTTaskManager.php");
				require_once 'modules/com_vtiger_workflow/tasks/' . $className . '.php';
				$unserializeTask = unserialize($task);
				if (array_key_exists("field_value_mapping", $unserializeTask)) {
					$fieldMapping = \App\Json::decode($unserializeTask->field_value_mapping);
					if (!empty($fieldMapping)) {
						foreach ($fieldMapping as $key => $condition) {
							if ($condition['fieldname'] == $pickListFieldName) {
								$value = $condition['value'];
								$explodedValueArray = explode(',', $value);
								foreach ($valueToDelete as $value) {
									$arrayKey = array_search($value, $explodedValueArray);
									if ($arrayKey !== false) {
										$explodedValueArray[$arrayKey] = $replaceValue;
									}
								}
								$value = implode(',', $explodedValueArray);
								$condition['value'] = $value;
							}
							$fieldMapping[$key] = $condition;
						}
						$updatedTask = \App\Json::encode($fieldMapping);
						$unserializeTask->field_value_mapping = $updatedTask;
						$serializeTask = serialize($unserializeTask);
						$query = 'UPDATE com_vtiger_workflowtasks SET task=? where workflow_id=? && task_id=?';
						$db->pquery($query, array($serializeTask, $row['workflow_id'], $row['task_id']));
					}
				} else {
					if ($className == 'VTCreateEventTask') {
						if ($pickListFieldName == 'activitystatus') {
							$pickListFieldName = 'status';
						} elseif ($pickListFieldName == 'activitytype') {
							$pickListFieldName = 'eventType';
						}
					} elseif ($className == 'VTCreateTodoTask') {
						if ($pickListFieldName == 'activitystatus') {
							$pickListFieldName = 'status';
						} elseif ($pickListFieldName == 'taskpriority') {
							$pickListFieldName = 'priority';
						}
					}
					if (array_key_exists($pickListFieldName, $unserializeTask)) {
						$value = $unserializeTask->$pickListFieldName;
						$explodedValueArray = explode(',', $value);
						foreach ($valueToDelete as $value) {
							$arrayKey = array_search($value, $explodedValueArray);
							if ($arrayKey !== false) {
								$explodedValueArray[$arrayKey] = $replaceValue;
							}
						}
						$value = implode(',', $explodedValueArray);
						$unserializeTask->$pickListFieldName = $value;
						$serializeTask = serialize($unserializeTask);
						$query = 'UPDATE com_vtiger_workflowtasks SET task=? where workflow_id=? && task_id=?';
						$db->pquery($query, array($serializeTask, $row['workflow_id'], $row['task_id']));
					}
				}
			}
		}
	}
}

?>
