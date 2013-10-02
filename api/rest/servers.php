<?php
class ChRest_Servers extends Extension_RestController implements IExtensionRestController {
	function getAction($stack) {
		@$action = array_shift($stack);
		
		// Looking up a single ID?
		if(is_numeric($action)) {
			$this->getId(intval($action));
			
		} else { // actions
			switch($action) {
			}
		}
		
		$this->error(self::ERRNO_NOT_IMPLEMENTED);
	}
	
	function putAction($stack) {
		@$action = array_shift($stack);
		
		// Looking up a single ID?
		if(is_numeric($action)) {
			$this->putId(intval($action));
			
		} else { // actions
			switch($action) {
			}
		}
		
		$this->error(self::ERRNO_NOT_IMPLEMENTED);
	}
	
	function postAction($stack) {
		@$action = array_shift($stack);
		
		if(is_numeric($action) && !empty($stack)) {
			$id = intval($action);
			$action = array_shift($stack);
			
			switch($action) {
// 				case 'note':
// 					$this->postNote($id);
// 					break;
			}
			
		} else {
			switch($action) {
				case 'create':
					$this->postCreate();
					break;
				case 'search':
					$this->postSearch();
					break;
			}
		}
		
		$this->error(self::ERRNO_NOT_IMPLEMENTED);
	}
	
	function deleteAction($stack) {
		$id = array_shift($stack);

		if(null == ($server = DAO_Server::get($id)))
			$this->error(self::ERRNO_CUSTOM, sprintf("Invalid server ID %d", $id));

		DAO_Server::delete($id);

		$result = array('id' => $id);
		$this->success($result);
	}

	function translateToken($token, $type='dao') {
		$tokens = array();
		
		if('dao'==$type) {
			$tokens = array(
				'name' => DAO_Server::NAME,
			);
		} else {
			$tokens = array(
				'id' => SearchFields_Server::ID,
				'name' => SearchFields_Server::NAME,
			);
		}
		
		if(isset($tokens[$token]))
			return $tokens[$token];
		
		return NULL;
	}

	function getContext($id) {
		$labels = array();
		$values = array();
		$context = CerberusContexts::getContext(CerberusContexts::CONTEXT_SERVER, $id, $labels, $values, null, true);

		return $values;
	}
	
	function getId($id) {
		$worker = CerberusApplication::getActiveWorker();
		
		$container = $this->search(array(
			array('id', '=', $id),
		));
		
		if(is_array($container) && isset($container['results']) && isset($container['results'][$id])) {
			$record = $container['results'][$id];
			
			if(isset($container['results_labels']))
				$record['_labels'] =$container['results_labels'];
			
			if(isset($container['results_types']))
				$record['_types'] =$container['results_types'];
			
			$this->success($record);
		}

		// Error
		$this->error(self::ERRNO_CUSTOM, sprintf("Invalid server id '%d'", $id));
	}
	
	function search($filters=array(), $sortToken='id', $sortAsc=1, $page=1, $limit=10) {
		$worker = CerberusApplication::getActiveWorker();

		$custom_field_params = $this->_handleSearchBuildParamsCustomFields($filters, CerberusContexts::CONTEXT_SERVER);
		$params = $this->_handleSearchBuildParams($filters);
		$params = array_merge($params, $custom_field_params);
				
		// Sort
		$sortBy = $this->translateToken($sortToken, 'search');
		$sortAsc = !empty($sortAsc) ? true : false;
		
		// Search
		list($results, $total) = DAO_Server::search(
			!empty($sortBy) ? array($sortBy) : array(),
			$params,
			$limit,
			max(0,$page-1),
			$sortBy,
			$sortAsc,
			true
		);
		
		$objects = array();
		
		$_types = array();
		$_labels = array();
		
		foreach($results as $id => $result) {
			$values = $this->getContext($id);

			if(empty($_labels) && isset($values['_labels'])) {
				$_labels = $values['_labels'];
			}
			
			if(empty($_types) && isset($values['_types'])) {
				$_types = $values['_types'];
			}
			
			unset($values['_labels']);
			unset($values['_types']);
			
			$objects[$id] = $values;
		}
		
		$container = array(
			'total' => $total,
			'count' => count($objects),
			'page' => $page,
			'results' => $objects,
			'results_labels' => $_labels,
			'results_types' => $_types,
		);
		
		return $container;
	}
	
	function postSearch() {
		$container = $this->_handlePostSearch();
		$this->success($container);
	}
	
	function putId($id) {
		$worker = CerberusApplication::getActiveWorker();
		
		// Validate the ID
		if(null == ($server = DAO_Server::get($id)))
			$this->error(self::ERRNO_CUSTOM, sprintf("Invalid server ID '%d'", $id));
			
		$putfields = array(
			'name' => 'string',
		);

		$fields = array();

		foreach($putfields as $putfield => $type) {
			if(!isset($_POST[$putfield]))
				continue;
			
			@$value = DevblocksPlatform::importGPC($_POST[$putfield], 'string', '');
			
			if(null == ($field = self::translateToken($putfield, 'dao'))) {
				$this->error(self::ERRNO_CUSTOM, sprintf("'%s' is not a valid field.", $putfield));
			}
			
			// Sanitize
			$value = DevblocksPlatform::importVar($value, $type);
						
//			switch($field) {
//				case DAO_Worker::PASSWORD:
//					$value = md5($value);
//					break;
//			}
			
			$fields[$field] = $value;
		}
		
		// [TODO] All records should have an updated timestamp for syncing
		//if(!isset($fields[DAO_Server::UPDATED]))
		//	$fields[DAO_Server::UPDATED] = time();
		
		// Handle custom fields
		$customfields = $this->_handleCustomFields($_POST);
		if(is_array($customfields))
			DAO_CustomFieldValue::formatAndSetFieldValues(CerberusContexts::CONTEXT_SERVER, $id, $customfields, true, true, true);
		
		// Check required fields
//		$reqfields = array(DAO_Address::EMAIL);
//		$this->_handleRequiredFields($reqfields, $fields);

		// Update
		DAO_Server::update($id, $fields);
		$this->getId($id);
	}
	
	function postCreate() {
		$worker = CerberusApplication::getActiveWorker();
		
		$postfields = array(
			'name' => 'string',
		);

		$fields = array();
		
		foreach($postfields as $postfield => $type) {
			if(!isset($_POST[$postfield]))
				continue;
				
			@$value = DevblocksPlatform::importGPC($_POST[$postfield], 'string', '');
				
			if(null == ($field = self::translateToken($postfield, 'dao'))) {
				$this->error(self::ERRNO_CUSTOM, sprintf("'%s' is not a valid field.", $postfield));
			}

			// Sanitize
			$value = DevblocksPlatform::importVar($value, $type);
			
			switch($field) {
				default:
					$fields[$field] = $value;
					break;
			}
		}

		// Defaults
		// [TODO]
		//if(!isset($fields[DAO_Server::CREATED]))
		//	$fields[DAO_Server::CREATED] = time();
		
		// Check required fields
		$reqfields = array(
			DAO_Server::NAME,
		);
		$this->_handleRequiredFields($reqfields, $fields);
		
		// Create
		if(false != ($id = DAO_Server::create($fields))) {
			// Handle custom fields
			$customfields = $this->_handleCustomFields($_POST);
			if(is_array($customfields))
				DAO_CustomFieldValue::formatAndSetFieldValues(CerberusContexts::CONTEXT_SERVER, $id, $customfields, true, true, true);
			
			$this->getId($id);
		}
	}

};