<?php
class Context_Server extends Extension_DevblocksContext implements IDevblocksContextProfile, IDevblocksContextPeek, IDevblocksContextImport, IDevblocksContextAutocomplete {
	function getRandom() {
		return DAO_Server::random();
	}
	
	function profileGetUrl($context_id) {
		if(empty($context_id))
			return '';
	
		$url_writer = DevblocksPlatform::getUrlService();
		$url = $url_writer->writeNoProxy('c=profiles&type=server&id='.$context_id, true);
		return $url;
	}
	
	function autocomplete($term) {
		$results = DAO_Server::autocomplete($term);
		$list = array();
		
		if(stristr('none', $term) || stristr('empty', $term) || stristr('null', $term)) {
			$empty = new stdClass();
			$empty->label = '(no server)';
			$empty->value = '0';
			$empty->meta = array('desc' => 'Clear the server');
			$list[] = $empty;
		}
		
		if(is_array($results))
		foreach($results as $server_id => $server){
			$entry = new stdClass();
			$entry->label = $server->name;
			$entry->value = intval($server_id);
			
			$meta = array();
			
			$entry->meta = $meta;
			$list[] = $entry;
		}
		
		return $list;
	}
	
	function getMeta($context_id) {
		$server = DAO_Server::get($context_id);

		$url = $this->profileGetUrl($context_id);
		$friendly = DevblocksPlatform::strToPermalink($server->name);
		
		if(!empty($friendly))
			$url .= '-' . $friendly;
		
		return array(
			'id' => $server->id,
			'name' => $server->name,
			'permalink' => $url,
			'updated' => $server->updated,
		);
	}
	
	function getPropertyLabels(DevblocksDictionaryDelegate $dict) {
		$labels = $dict->_labels;
		$prefix = $labels['_label'];
		
		if(!empty($prefix)) {
			array_walk($labels, function(&$label, $key) use ($prefix) {
				$label = preg_replace(sprintf("#^%s #", preg_quote($prefix)), '', $label);
				
				// [TODO] Use translations
				switch($key) {
				}
				
				$label = mb_convert_case($label, MB_CASE_LOWER);
				$label[0] = mb_convert_case($label[0], MB_CASE_UPPER);
			});
		}
		
		asort($labels);
		
		return $labels;
	}
	
	// [TODO] Interface
	function getDefaultProperties() {
		return array(
		);
	}
	
	function getContext($server, &$token_labels, &$token_values, $prefix=null) {
		if(is_null($prefix))
			$prefix = 'Server:';
		
		$translate = DevblocksPlatform::getTranslationService();
		$fields = DAO_CustomField::getByContext(CerberusContexts::CONTEXT_SERVER);
		
		// Polymorph
		if(is_numeric($server)) {
			$server = DAO_Server::get($server);
		} elseif($server instanceof Model_Server) {
			// It's what we want already.
		} elseif(is_array($server)) {
			$server = Cerb_ORMHelper::recastArrayToModel($server, 'Model_Server');
		} else {
			$server = null;
		}
			
		// Token labels
		$token_labels = array(
			'_label' => $prefix,
			'created' => $prefix.$translate->_('common.created'),
			'id' => $prefix.$translate->_('common.id'),
			'name' => $prefix.$translate->_('common.name'),
			'updated' => $prefix.$translate->_('common.updated'),
			'record_url' => $prefix.$translate->_('common.url.record'),
		);
		
		// Token types
		$token_types = array(
			'_label' => 'context_url',
			'created' => Model_CustomField::TYPE_DATE,
			'id' => Model_CustomField::TYPE_NUMBER,
			'name' => Model_CustomField::TYPE_SINGLE_LINE,
			'updated' => Model_CustomField::TYPE_DATE,
			'record_url' => Model_CustomField::TYPE_URL,
		);
		
		// Custom field/fieldset token labels
		if(false !== ($custom_field_labels = $this->_getTokenLabelsFromCustomFields($fields, $prefix)) && is_array($custom_field_labels))
			$token_labels = array_merge($token_labels, $custom_field_labels);
		
		// Custom field/fieldset token types
		if(false !== ($custom_field_types = $this->_getTokenTypesFromCustomFields($fields, $prefix)) && is_array($custom_field_types))
			$token_types = array_merge($token_types, $custom_field_types);
		
		// Token values
		$token_values = array();
		
		$token_values['_context'] = CerberusContexts::CONTEXT_SERVER;
		$token_values['_types'] = $token_types;
		
		// Custom token values
		if(null != $server) {
			$token_values['_loaded'] = true;
			$token_values['_label'] = $server->name;
			$token_values['created'] = $server->created;
			$token_values['id'] = $server->id;
			$token_values['name'] = $server->name;
			$token_values['updated'] = $server->updated;
			
			// Custom fields
			$token_values = $this->_importModelCustomFieldsAsValues($server, $token_values);
			
			// URL
			$url_writer = DevblocksPlatform::getUrlService();
			$token_values['record_url'] = $url_writer->writeNoProxy(sprintf("c=datacenter&tab=server&id=%d-%s",$server->id, DevblocksPlatform::strToPermalink($server->name)), true);
		}
		
		return true;
	}

	function lazyLoadContextValues($token, $dictionary) {
		if(!isset($dictionary['id']))
			return;
		
		$context = CerberusContexts::CONTEXT_SERVER;
		$context_id = $dictionary['id'];
		
		@$is_loaded = $dictionary['_loaded'];
		$values = array();
		
		if(!$is_loaded) {
			$labels = array();
			CerberusContexts::getContext($context, $context_id, $labels, $values, null, true);
		}

		switch($token) {
			case 'watchers':
				$watchers = array(
					$token => CerberusContexts::getWatchers($context, $context_id, true),
				);
				$values = array_merge($values, $watchers);
				break;
				
			default:
				if(substr($token,0,7) == 'custom_') {
					$fields = $this->_lazyLoadCustomFields($token, $context, $context_id);
					$values = array_merge($values, $fields);
				}
				break;
		}
		
		return $values;
	}
	
	function getChooserView($view_id=null) {
		if(empty($view_id))
			$view_id = 'chooser_'.str_replace('.','_',$this->id).time().mt_rand(0,9999);
	
		// View
		$defaults = C4_AbstractViewModel::loadFromClass($this->getViewClass());
		$defaults->id = $view_id;
		$defaults->is_ephemeral = true;
		
		$view = C4_AbstractViewLoader::getView($view_id, $defaults);
		
		$view->renderSortBy = SearchFields_Server::NAME;
		$view->renderSortAsc = true;
		$view->renderLimit = 10;
		$view->renderFilters = false;
		$view->renderTemplate = 'contextlinks_chooser';
		
		return $view;
	}
	
	function getView($context=null, $context_id=null, $options=array(), $view_id=null) {
		$view_id = !empty($view_id) ? $view_id : str_replace('.','_',$this->id);
		
		$defaults = C4_AbstractViewModel::loadFromClass($this->getViewClass());
		$defaults->id = $view_id;

		$view = C4_AbstractViewLoader::getView($view_id, $defaults);
		//$view->name = 'Sites';
		
		$params_req = array();
		
		if(!empty($context) && !empty($context_id)) {
			$params_req = array(
				new DevblocksSearchCriteria(SearchFields_Server::CONTEXT_LINK,'=',$context),
				new DevblocksSearchCriteria(SearchFields_Server::CONTEXT_LINK_ID,'=',$context_id),
			);
		}
		
		$view->addParamsRequired($params_req, true);
		
		$view->renderTemplate = 'context';
		return $view;
	}
	
	function renderPeekPopup($context_id=0, $view_id='', $edit=false) {
		$id = $context_id;
		
		$tpl = DevblocksPlatform::getTemplateService();
		$tpl->assign('view_id', $view_id);
		
		// Model
		$model = null;
		if(empty($id) || null == ($model = DAO_Server::get($id)))
			$model = new Model_Server();
		
		if(empty($context_id) || $edit) {
			$tpl->assign('model', $model);
			
			// Custom fields
			$custom_fields = DAO_CustomField::getByContext(CerberusContexts::CONTEXT_SERVER, false);
			$tpl->assign('custom_fields', $custom_fields);
	
			$custom_field_values = DAO_CustomFieldValue::getValuesByContextIds(CerberusContexts::CONTEXT_SERVER, $id);
			if(isset($custom_field_values[$id]))
				$tpl->assign('custom_field_values', $custom_field_values[$id]);
			
			$types = Model_CustomField::getTypes();
			$tpl->assign('types', $types);
			
			// Render
			$tpl->display('devblocks:cerberusweb.datacenter.servers::datacenter/servers/peek_edit.tpl');
			
		} else {
			// Counts
			$activity_counts = array(
				'comments' => DAO_Comment::count(CerberusContexts::CONTEXT_SERVER, $context_id),
				'domains' => DAO_Domain::countByServerId($context_id),
			);
			$tpl->assign('activity_counts', $activity_counts);
			
			// Links
			$links = array(
				CerberusContexts::CONTEXT_SERVER => array(
					$context_id => 
						DAO_ContextLink::getContextLinkCounts(
							CerberusContexts::CONTEXT_SERVER,
							$context_id,
							array(CerberusContexts::CONTEXT_WORKER, CerberusContexts::CONTEXT_CUSTOM_FIELDSET)
						),
				),
			);
			$tpl->assign('links', $links);
			
			// Dictionary
			$labels = array();
			$values = array();
			CerberusContexts::getContext(CerberusContexts::CONTEXT_SERVER, $model, $labels, $values, '', true, false);
			$dict = DevblocksDictionaryDelegate::instance($values);
			$tpl->assign('dict', $dict);
			$tpl->assign('properties',
				array(
					'updated',
				)
			);
			
			$tpl->display('devblocks:cerberusweb.datacenter.servers::datacenter/servers/peek.tpl');
		}
	}
	
	function importGetKeys() {
		// [TODO] Translate
	
		$keys = array(
			'created' => array(
				'label' => 'Created Date',
				'type' => Model_CustomField::TYPE_DATE,
				'param' => SearchFields_Server::CREATED,
			),
			'name' => array(
				'label' => 'Name',
				'type' => Model_CustomField::TYPE_SINGLE_LINE,
				'param' => SearchFields_Server::NAME,
				'required' => true,
				'force_match' => true,
			),
			'updated' => array(
				'label' => 'Updated Date',
				'type' => Model_CustomField::TYPE_DATE,
				'param' => SearchFields_Server::UPDATED,
			),
		);
	
		$fields = SearchFields_Server::getFields();
		self::_getImportCustomFields($fields, $keys);
		
		DevblocksPlatform::sortObjects($keys, '[label]', true);
	
		return $keys;
	}
	
	function importKeyValue($key, $value) {
		switch($key) {
		}
	
		return $value;
	}
	
	function importSaveObject(array $fields, array $custom_fields, array $meta) {
		// If new...
		if(!isset($meta['object_id']) || empty($meta['object_id'])) {
			// Make sure we have a ame
			if(!isset($fields[DAO_Server::NAME])) {
				$fields[DAO_Server::NAME] = 'New ' . $this->manifest->name;
			}
				
			// Create
			$meta['object_id'] = DAO_Server::create($fields);
				
		} else {
			// Update
			DAO_Server::update($meta['object_id'], $fields);
		}
	
		// Custom fields
		if(!empty($custom_fields) && !empty($meta['object_id'])) {
			DAO_CustomFieldValue::formatAndSetFieldValues($this->manifest->id, $meta['object_id'], $custom_fields, false, true, true);
		}
	}
};

class DAO_Server extends Cerb_ORMHelper {
	const CACHE_ALL = 'cerberus_cache_servers_all';
	
	const CREATED = 'created';
	const ID = 'id';
	const NAME = 'name';
	const UPDATED = 'updated';

	static function create($fields) {
		$db = DevblocksPlatform::getDatabaseService();
		
		$sql = "INSERT INTO server () VALUES ()";
		$db->ExecuteMaster($sql);
		$id = $db->LastInsertId();
		
		if(!isset($fields[self::CREATED]))
			$fields[self::CREATED] = time();
		
		self::update($id, $fields);
		
		return $id;
	}
	
	static function update($ids, $fields, $check_deltas=true) {
		if(!is_array($ids))
			$ids = array($ids);
		
		if(!isset($fields[self::UPDATED]))
			$fields[self::UPDATED] = time();
		
		// Make a diff for the requested objects in batches
		
		$chunks = array_chunk($ids, 100, true);
		while($batch_ids = array_shift($chunks)) {
			if(empty($batch_ids))
				continue;
			
			// Send events
			if($check_deltas) {
				CerberusContexts::checkpointChanges(CerberusContexts::CONTEXT_SERVER, $batch_ids);
			}
			
			// Make changes
			parent::_update($batch_ids, 'server', $fields);
			
			// Send events
			if($check_deltas) {
				
				// Trigger an event about the changes
				$eventMgr = DevblocksPlatform::getEventService();
				$eventMgr->trigger(
					new Model_DevblocksEvent(
						'dao.server.update',
						array(
							'fields' => $fields,
						)
					)
				);
				
				// Log the context update
				DevblocksPlatform::markContextChanged(CerberusContexts::CONTEXT_SERVER, $batch_ids);
			}
		}

		self::clearCache();
	}
	
	static function updateWhere($fields, $where) {
		if(!isset($fields[self::UPDATED]))
			$fields[self::UPDATED] = time();
		
		parent::_updateWhere('server', $fields, $where);
		self::clearCache();
	}
	
	static function getAll($nocache=false) {
		$cache = DevblocksPlatform::getCacheService();
		if($nocache || null === ($servers = $cache->load(self::CACHE_ALL))) {
			$servers = self::getWhere(
				null,
				DAO_Server::NAME,
				true,
				null,
				Cerb_ORMHelper::OPT_GET_MASTER_ONLY
			);
			
			if(!is_array($servers))
				return false;
			
			$cache->save($servers, self::CACHE_ALL);
		}
		
		return $servers;
	}
	
	/**
	 * @param string $where
	 * @param mixed $sortBy
	 * @param mixed $sortAsc
	 * @param integer $limit
	 * @return Model_Server[]
	 */
	static function getWhere($where=null, $sortBy=DAO_Server::NAME, $sortAsc=true, $limit=null, $options=null) {
		$db = DevblocksPlatform::getDatabaseService();

		list($where_sql, $sort_sql, $limit_sql) = self::_getWhereSQL($where, $sortBy, $sortAsc, $limit);
		
		// SQL
		$sql = "SELECT id, name, created, updated ".
			"FROM server ".
			$where_sql.
			$sort_sql.
			$limit_sql
		;

		if($options & Cerb_ORMHelper::OPT_GET_MASTER_ONLY) {
			$rs = $db->ExecuteMaster($sql, _DevblocksDatabaseManager::OPT_NO_READ_AFTER_WRITE);
		} else {
			$rs = $db->ExecuteSlave($sql);
		}
		
		return self::_getObjectsFromResult($rs);
	}

	/**
	 * @param integer $id
	 * @return Model_Server
	 */
	static function get($id) {
		if(empty($id))
			return null;
		
		$objects = self::getAll();
		
		if(isset($objects[$id]))
			return $objects[$id];
		
		return null;
	}
	
	static function getByName($name) {
		if(empty($name))
			return null;
		
		$results = self::getWhere(
			sprintf("%s = %s", self::escape(DAO_Server::NAME), self::qstr($name)),
			null,
			null,
			1
		);
		
		if(is_array($results) && !empty($results))
			return array_shift($results);
		
		return null;
	}
	
	/**
	 * @param resource $rs
	 * @return Model_Server[]
	 */
	static private function _getObjectsFromResult($rs) {
		$objects = array();
		
		if(!($rs instanceof mysqli_result))
			return false;
		
		while($row = mysqli_fetch_assoc($rs)) {
			$object = new Model_Server();
			$object->created = intval($row['created']);
			$object->id = intval($row['id']);
			$object->name = $row['name'];
			$object->updated = intval($row['updated']);
			$objects[$object->id] = $object;
		}
		
		mysqli_free_result($rs);
		
		return $objects;
	}
	
	static function delete($ids) {
		if(!is_array($ids)) $ids = array($ids);
		$db = DevblocksPlatform::getDatabaseService();
		
		if(empty($ids))
			return;
		
		$ids_list = implode(',', $ids);
		
		$db->ExecuteMaster(sprintf("DELETE FROM server WHERE id IN (%s)", $ids_list));
		
		// Fire event
		$eventMgr = DevblocksPlatform::getEventService();
		$eventMgr->trigger(
			new Model_DevblocksEvent(
				'context.delete',
				array(
					'context' => CerberusContexts::CONTEXT_SERVER,
					'context_ids' => $ids
				)
			)
		);
		
		self::clearCache();
		
		return true;
	}
	
	public static function maint() {
		// Fire event
		$eventMgr = DevblocksPlatform::getEventService();
		$eventMgr->trigger(
			new Model_DevblocksEvent(
				'context.maint',
				array(
					'context' => CerberusContexts::CONTEXT_SERVER,
					'context_table' => 'server',
					'context_key' => 'id',
				)
			)
		);
	}
	
	public static function random() {
		return self::_getRandom('server');
	}
	
	public static function getSearchQueryComponents($columns, $params, $sortBy=null, $sortAsc=null) {
		$fields = SearchFields_Server::getFields();
		
		list($tables,$wheres) = parent::_parseSearchParams($params, $columns, $fields, $sortBy, array(), 'server.id');
		
		$select_sql = sprintf("SELECT ".
			"server.id as %s, ".
			"server.created as %s, ".
			"server.name as %s, ".
			"server.updated as %s ",
				SearchFields_Server::ID,
				SearchFields_Server::CREATED,
				SearchFields_Server::NAME,
				SearchFields_Server::UPDATED
			);
			
		$join_sql = "FROM server ".
			// [JAS]: Dynamic table joins
			(isset($tables['context_link']) ? "INNER JOIN context_link ON (context_link.to_context = 'cerberusweb.contexts.datacenter.server' AND context_link.to_context_id = server.id) " : " ")
		;
		
		// Custom field joins
		list($select_sql, $join_sql, $has_multiple_values) = self::_appendSelectJoinSqlForCustomFieldTables(
			$tables,
			$params,
			'server.id',
			$select_sql,
			$join_sql
		);
				
		$where_sql = "".
			(!empty($wheres) ? sprintf("WHERE %s ",implode(' AND ',$wheres)) : "WHERE 1 ");
			
		$sort_sql = self::_buildSortClause($sortBy, $sortAsc, $fields);

		// Translate virtual fields
		
		$args = array(
			'join_sql' => &$join_sql,
			'where_sql' => &$where_sql,
			'tables' => &$tables,
			'has_multiple_values' => &$has_multiple_values
		);
		
		array_walk_recursive(
			$params,
			array('DAO_Server', '_translateVirtualParameters'),
			$args
		);
		
		$result = array(
			'primary_table' => 'server',
			'select' => $select_sql,
			'join' => $join_sql,
			'where' => $where_sql,
			'has_multiple_values' => $has_multiple_values,
			'sort' => $sort_sql,
		);
		
		return $result;
	}
	
	private static function _translateVirtualParameters($param, $key, &$args) {
		if(!is_a($param, 'DevblocksSearchCriteria'))
			return;
		
		$from_context = CerberusContexts::CONTEXT_SERVER;
		$from_index = 'server.id';
		
		$param_key = $param->field;
		settype($param_key, 'string');
		
		switch($param_key) {
			case SearchFields_Server::FULLTEXT_COMMENT_CONTENT:
				$search = Extension_DevblocksSearchSchema::get(Search_CommentContent::ID);
				$query = $search->getQueryFromParam($param);
				
				if(false === ($ids = $search->query($query, array('context_crc32' => sprintf("%u", crc32($from_context)))))) {
					$args['where_sql'] .= 'AND 0 ';
				
				} elseif(is_array($ids)) {
					$from_ids = DAO_Comment::getContextIdsByContextAndIds($from_context, $ids);
					
					$args['where_sql'] .= sprintf('AND %s IN (%s) ',
						$from_index,
						implode(', ', (!empty($from_ids) ? $from_ids : array(-1)))
					);
					
				} elseif(is_string($ids)) {
					$db = DevblocksPlatform::getDatabaseService();
					$temp_table = sprintf("_tmp_%s", uniqid());
					
					$db->ExecuteSlave(sprintf("CREATE TEMPORARY TABLE %s (PRIMARY KEY (id)) SELECT DISTINCT context_id AS id FROM comment INNER JOIN %s ON (%s.id=comment.id)",
						$temp_table,
						$ids,
						$ids
					));
					
					$args['join_sql'] .= sprintf("INNER JOIN %s ON (%s.id=server.id) ",
						$temp_table,
						$temp_table
					);
				}

				break;
			
			case SearchFields_Server::VIRTUAL_CONTEXT_LINK:
				$args['has_multiple_values'] = true;
				self::_searchComponentsVirtualContextLinks($param, $from_context, $from_index, $args['join_sql'], $args['where_sql']);
				break;
				
			case SearchFields_Server::VIRTUAL_HAS_FIELDSET:
				self::_searchComponentsVirtualHasFieldset($param, $from_context, $from_index, $args['join_sql'], $args['where_sql']);
				break;
			
			case SearchFields_Server::VIRTUAL_WATCHERS:
				$args['has_multiple_values'] = true;
				self::_searchComponentsVirtualWatchers($param, $from_context, $from_index, $args['join_sql'], $args['where_sql'], $args['tables']);
				break;
		}
	}
	
	static function autocomplete($term) {
		$db = DevblocksPlatform::getDatabaseService();
		$ids = array();
		
		$results = $db->GetArraySlave(sprintf("SELECT id ".
			"FROM server ".
			"WHERE name LIKE %s",
			$db->qstr($term.'%')
		));
		
		
		if(is_array($results))
		foreach($results as $row) {
			$ids[] = $row['id'];
		}
		
		return DAO_Server::getIds($ids);
	}
	
	/**
	 * Enter description here...
	 *
	 * @param array $columns
	 * @param DevblocksSearchCriteria[] $params
	 * @param integer $limit
	 * @param integer $page
	 * @param string $sortBy
	 * @param boolean $sortAsc
	 * @param boolean $withCounts
	 * @return array
	 */
	static function search($columns, $params, $limit=10, $page=0, $sortBy=null, $sortAsc=null, $withCounts=true) {
		$db = DevblocksPlatform::getDatabaseService();
	
		// Build search queries
		$query_parts = self::getSearchQueryComponents($columns,$params,$sortBy,$sortAsc);

		$select_sql = $query_parts['select'];
		$join_sql = $query_parts['join'];
		$where_sql = $query_parts['where'];
		$has_multiple_values = $query_parts['has_multiple_values'];
		$sort_sql = $query_parts['sort'];
		
		$sql =
			$select_sql.
			$join_sql.
			$where_sql.
			($has_multiple_values ? 'GROUP BY server.id ' : '').
			$sort_sql;
			
		// [TODO] Could push the select logic down a level too
		if($limit > 0) {
			if(false == ($rs = $db->SelectLimit($sql,$limit,$page*$limit)))
				return false;
		} else {
			if(false == ($rs = $db->ExecuteSlave($sql)))
				return false;
			$total = mysqli_num_rows($rs);
		}
		
		if(!($rs instanceof mysqli_result))
			return false;
		
		$results = array();
		
		while($row = mysqli_fetch_assoc($rs)) {
			$object_id = intval($row[SearchFields_Server::ID]);
			$results[$object_id] = $row;
		}

		$total = count($results);
		
		if($withCounts) {
			// We can skip counting if we have a less-than-full single page
			if(!(0 == $page && $total < $limit)) {
				$count_sql =
					($has_multiple_values ? "SELECT COUNT(DISTINCT server.id) " : "SELECT COUNT(server.id) ").
					$join_sql.
					$where_sql;
				$total = $db->GetOneSlave($count_sql);
			}
		}
		
		mysqli_free_result($rs);
		
		return array($results,$total);
	}

	static public function clearCache() {
		$cache = DevblocksPlatform::getCacheService();
		$cache->remove(self::CACHE_ALL);
	}
};

class SearchFields_Server implements IDevblocksSearchFields {
	const CREATED = 's_created';
	const ID = 's_id';
	const NAME = 's_name';
	const UPDATED = 's_updated';
	
	const VIRTUAL_CONTEXT_LINK = '*_context_link';
	const VIRTUAL_HAS_FIELDSET = '*_has_fieldset';
	const VIRTUAL_WATCHERS = '*_workers';
	
	const CONTEXT_LINK = 'cl_context_from';
	const CONTEXT_LINK_ID = 'cl_context_from_id';
	
	// Comment Content
	const FULLTEXT_COMMENT_CONTENT = 'ftcc_content';
	
	/**
	 * @return DevblocksSearchField[]
	 */
	static function getFields() {
		$translate = DevblocksPlatform::getTranslationService();
		
		$columns = array(
			self::CREATED => new DevblocksSearchField(self::CREATED, 'server', 'created', $translate->_('common.created'), Model_CustomField::TYPE_DATE, true),
			self::ID => new DevblocksSearchField(self::ID, 'server', 'id', $translate->_('common.id'), null, true),
			self::NAME => new DevblocksSearchField(self::NAME, 'server', 'name', $translate->_('common.name'), Model_CustomField::TYPE_SINGLE_LINE, true),
			self::UPDATED => new DevblocksSearchField(self::UPDATED, 'server', 'updated', $translate->_('common.updated'), Model_CustomField::TYPE_DATE, true),
			
			self::VIRTUAL_CONTEXT_LINK => new DevblocksSearchField(self::VIRTUAL_CONTEXT_LINK, '*', 'context_link', $translate->_('common.links'), null, false),
			self::VIRTUAL_HAS_FIELDSET => new DevblocksSearchField(self::VIRTUAL_HAS_FIELDSET, '*', 'has_fieldset', $translate->_('common.fieldset'), null, false),
			self::VIRTUAL_WATCHERS => new DevblocksSearchField(self::VIRTUAL_WATCHERS, '*', 'workers', $translate->_('common.watchers'), 'WS', false),
			
			self::CONTEXT_LINK => new DevblocksSearchField(self::CONTEXT_LINK, 'context_link', 'from_context', null, null, false),
			self::CONTEXT_LINK_ID => new DevblocksSearchField(self::CONTEXT_LINK_ID, 'context_link', 'from_context_id', null, null, false),
				
			self::FULLTEXT_COMMENT_CONTENT => new DevblocksSearchField(self::FULLTEXT_COMMENT_CONTENT, 'ftcc', 'content', $translate->_('comment.filters.content'), 'FT', false),
		);
		
		// Fulltext indexes
		
		$columns[self::FULLTEXT_COMMENT_CONTENT]->ft_schema = Search_CommentContent::ID;
		
		// Custom fields with fieldsets
		
		$custom_columns = DevblocksSearchField::getCustomSearchFieldsByContexts(array(
			CerberusContexts::CONTEXT_SERVER,
		));
		
		if(is_array($custom_columns))
			$columns = array_merge($columns, $custom_columns);
		
		// Sort by label (translation-conscious)
		DevblocksPlatform::sortObjects($columns, 'db_label');

		return $columns;
	}
};

class Model_Server {
	public $created;
	public $id;
	public $name;
	public $updated;
};

class View_Server extends C4_AbstractView implements IAbstractView_Subtotals, IAbstractView_QuickSearch {
	const DEFAULT_ID = 'server';

	function __construct() {
		$translate = DevblocksPlatform::getTranslationService();
	
		$this->id = self::DEFAULT_ID;
		$this->name = $translate->_('Servers');
		$this->renderLimit = 25;
		$this->renderSortBy = SearchFields_Server::NAME;
		$this->renderSortAsc = true;

		$this->view_columns = array(
			SearchFields_Server::UPDATED,
		);
		
		// Filter cols
		$this->addColumnsHidden(array(
			SearchFields_Server::FULLTEXT_COMMENT_CONTENT,
			SearchFields_Server::VIRTUAL_CONTEXT_LINK,
			SearchFields_Server::VIRTUAL_HAS_FIELDSET,
			SearchFields_Server::VIRTUAL_WATCHERS,
		));
		
		// Filter params
		$this->addParamsHidden(array(
		));
		
		$this->doResetCriteria();
	}

	function getData() {
		$objects = DAO_Server::search(
			$this->view_columns,
			$this->getParams(),
			$this->renderLimit,
			$this->renderPage,
			$this->renderSortBy,
			$this->renderSortAsc,
			$this->renderTotal
		);
		return $objects;
	}

	function getDataAsObjects($ids=null) {
		return $this->_getDataAsObjects('DAO_Server', $ids);
	}
	
	function getDataSample($size) {
		return $this->_doGetDataSample('DAO_Server', $size);
	}
	
	function getSubtotalFields() {
		$all_fields = $this->getParamsAvailable(true);
		
		$fields = array();

		if(is_array($all_fields))
		foreach($all_fields as $field_key => $field_model) {
			$pass = false;
			
			switch($field_key) {
				// Virtuals
				case SearchFields_Server::VIRTUAL_CONTEXT_LINK:
				case SearchFields_Server::VIRTUAL_HAS_FIELDSET:
				case SearchFields_Server::VIRTUAL_WATCHERS:
					$pass = true;
					break;
					
				// Valid custom fields
				default:
					if('cf_' == substr($field_key,0,3))
						$pass = $this->_canSubtotalCustomField($field_key);
					break;
			}
			
			if($pass)
				$fields[$field_key] = $field_model;
		}

		return $fields;
	}
	
	function getSubtotalCounts($column) {
		$counts = array();
		$fields = $this->getFields();

		if(!isset($fields[$column]))
			return array();
		
		switch($column) {
			case SearchFields_Server::VIRTUAL_CONTEXT_LINK:
				$counts = $this->_getSubtotalCountForContextLinkColumn('DAO_Server', CerberusContexts::CONTEXT_SERVER, $column);
				break;
				
			case SearchFields_Server::VIRTUAL_HAS_FIELDSET:
				$counts = $this->_getSubtotalCountForHasFieldsetColumn('DAO_Server', CerberusContexts::CONTEXT_SERVER, $column);
				break;
			
			case SearchFields_Server::VIRTUAL_WATCHERS:
				$counts = $this->_getSubtotalCountForWatcherColumn('DAO_Server', $column);
				break;
			
			default:
				// Custom fields
				if('cf_' == substr($column,0,3)) {
					$counts = $this->_getSubtotalCountForCustomColumn('DAO_Server', $column, 'server.id');
				}
				
				break;
		}
		
		return $counts;
	}
	
	function getQuickSearchFields() {
		$search_fields = SearchFields_Server::getFields();
		
		$fields = array(
			'_fulltext' => 
				array(
					'type' => DevblocksSearchCriteria::TYPE_TEXT,
					'options' => array('param_key' => SearchFields_Server::NAME, 'match' => DevblocksSearchCriteria::OPTION_TEXT_PARTIAL),
				),
			'comments' => 
				array(
					'type' => DevblocksSearchCriteria::TYPE_FULLTEXT,
					'options' => array('param_key' => SearchFields_Server::FULLTEXT_COMMENT_CONTENT),
				),
			'created' => 
				array(
					'type' => DevblocksSearchCriteria::TYPE_DATE,
					'options' => array('param_key' => SearchFields_Server::CREATED),
				),
			'id' => 
				array(
					'type' => DevblocksSearchCriteria::TYPE_NUMBER,
					'options' => array('param_key' => SearchFields_Server::ID),
				),
			'name' => 
				array(
					'type' => DevblocksSearchCriteria::TYPE_TEXT,
					'options' => array('param_key' => SearchFields_Server::NAME, 'match' => DevblocksSearchCriteria::OPTION_TEXT_PARTIAL),
				),
			'updated' => 
				array(
					'type' => DevblocksSearchCriteria::TYPE_DATE,
					'options' => array('param_key' => SearchFields_Server::UPDATED),
				),
			'watchers' => 
				array(
					'type' => DevblocksSearchCriteria::TYPE_WORKER,
					'options' => array('param_key' => SearchFields_Server::VIRTUAL_WATCHERS),
				),
		);
		
		// Add searchable custom fields
		
		$fields = self::_appendFieldsFromQuickSearchContext(CerberusContexts::CONTEXT_SERVER, $fields, null);
		
		// Engine/schema examples: Comments
		
		$ft_examples = array();
		
		if(false != ($schema = Extension_DevblocksSearchSchema::get(Search_CommentContent::ID))) {
			if(false != ($engine = $schema->getEngine())) {
				$ft_examples = $engine->getQuickSearchExamples($schema);
			}
		}
		
		if(!empty($ft_examples))
			$fields['comments']['examples'] = $ft_examples;
		
		// Add is_sortable
		
		$fields = self::_setSortableQuickSearchFields($fields, $search_fields);
		
		// Sort by keys
		
		ksort($fields);
		
		return $fields;
	}	
	
	function getParamsFromQuickSearchFields($fields) {
		$search_fields = $this->getQuickSearchFields();
		$params = DevblocksSearchCriteria::getParamsFromQueryFields($fields, $search_fields);

		// Handle virtual fields and overrides
		if(is_array($fields))
		foreach($fields as $k => $v) {
			switch($k) {
				// ...
			}
		}
		
		return $params;
	}
	
	function render() {
		$this->_sanitize();
		
		$tpl = DevblocksPlatform::getTemplateService();
		$tpl->assign('id', $this->id);
		$tpl->assign('view', $this);

		// Custom fields
		$custom_fields = DAO_CustomField::getByContext(CerberusContexts::CONTEXT_SERVER);
		$tpl->assign('custom_fields', $custom_fields);

		switch($this->renderTemplate) {
			case 'contextlinks_chooser':
			default:
				$tpl->assign('view_template', 'devblocks:cerberusweb.datacenter.servers::datacenter/servers/view.tpl');
				$tpl->display('devblocks:cerberusweb.core::internal/views/subtotals_and_view.tpl');
				break;
		}
	}

	function renderCriteria($field) {
		$tpl = DevblocksPlatform::getTemplateService();
		$tpl->assign('id', $this->id);

		switch($field) {
			case SearchFields_Server::NAME:
				$tpl->display('devblocks:cerberusweb.core::internal/views/criteria/__string.tpl');
				break;
				
			case SearchFields_Server::ID:
				$tpl->display('devblocks:cerberusweb.core::internal/views/criteria/__number.tpl');
				break;
				
			case 'placeholder_bool':
				$tpl->display('devblocks:cerberusweb.core::internal/views/criteria/__bool.tpl');
				break;
				
			case SearchFields_Server::CREATED:
			case SearchFields_Server::UPDATED:
				$tpl->display('devblocks:cerberusweb.core::internal/views/criteria/__date.tpl');
				break;
				
			case SearchFields_Server::VIRTUAL_CONTEXT_LINK:
				$contexts = Extension_DevblocksContext::getAll(false);
				$tpl->assign('contexts', $contexts);
				$tpl->display('devblocks:cerberusweb.core::internal/views/criteria/__context_link.tpl');
				break;
				
			case SearchFields_Server::VIRTUAL_HAS_FIELDSET:
				$this->_renderCriteriaHasFieldset($tpl, CerberusContexts::CONTEXT_SERVER);
				break;
				
			case SearchFields_Server::VIRTUAL_WATCHERS:
				$tpl->display('devblocks:cerberusweb.core::internal/views/criteria/__context_worker.tpl');
				break;
				
			case SearchFields_Server::FULLTEXT_COMMENT_CONTENT:
				$tpl->display('devblocks:cerberusweb.core::internal/views/criteria/__fulltext.tpl');
				break;
				
			default:
				// Custom Fields
				if('cf_' == substr($field,0,3)) {
					$this->_renderCriteriaCustomField($tpl, substr($field,3));
				} else {
					echo ' ';
				}
				break;
		}
	}

	function renderVirtualCriteria($param) {
		$key = $param->field;
		
		switch($key) {
			case SearchFields_Server::VIRTUAL_CONTEXT_LINK:
				$this->_renderVirtualContextLinks($param);
				break;
				
			case SearchFields_Server::VIRTUAL_HAS_FIELDSET:
				$this->_renderVirtualHasFieldset($param);
				break;
			
			case SearchFields_Server::VIRTUAL_WATCHERS:
				$this->_renderVirtualWatchers($param);
				break;
		}
	}
	
	function renderCriteriaParam($param) {
		$field = $param->field;
		$values = !is_array($param->value) ? array($param->value) : $param->value;

		switch($field) {
			default:
				parent::renderCriteriaParam($param);
				break;
		}
	}

	function getFields() {
		return SearchFields_Server::getFields();
	}

	function doSetCriteria($field, $oper, $value) {
		$criteria = null;

		switch($field) {
			case SearchFields_Server::NAME:
				$criteria = $this->_doSetCriteriaString($field, $oper, $value);
				break;
				
			case SearchFields_Server::ID:
				$criteria = new DevblocksSearchCriteria($field,$oper,$value);
				break;
				
			case SearchFields_Server::CREATED:
			case SearchFields_Server::UPDATED:
				$criteria = $this->_doSetCriteriaDate($field, $oper);
				break;
				
			case 'placeholder_bool':
				@$bool = DevblocksPlatform::importGPC($_REQUEST['bool'],'integer',1);
				$criteria = new DevblocksSearchCriteria($field,$oper,$bool);
				break;
				
			case SearchFields_Server::VIRTUAL_CONTEXT_LINK:
				@$context_links = DevblocksPlatform::importGPC($_REQUEST['context_link'],'array',array());
				$criteria = new DevblocksSearchCriteria($field,DevblocksSearchCriteria::OPER_IN,$context_links);
				break;
				
			case SearchFields_Server::VIRTUAL_HAS_FIELDSET:
				@$options = DevblocksPlatform::importGPC($_REQUEST['options'],'array',array());
				$criteria = new DevblocksSearchCriteria($field,DevblocksSearchCriteria::OPER_IN,$options);
				break;
				
			case SearchFields_Server::VIRTUAL_WATCHERS:
				@$worker_ids = DevblocksPlatform::importGPC($_REQUEST['worker_id'],'array',array());
				$criteria = new DevblocksSearchCriteria($field,$oper,$worker_ids);
				break;
				
			case SearchFields_Server::FULLTEXT_COMMENT_CONTENT:
				@$scope = DevblocksPlatform::importGPC($_REQUEST['scope'],'string','expert');
				$criteria = new DevblocksSearchCriteria($field,DevblocksSearchCriteria::OPER_FULLTEXT,array($value,$scope));
				break;
				
			default:
				// Custom Fields
				if(substr($field,0,3)=='cf_') {
					$criteria = $this->_doSetCriteriaCustomField($field, substr($field,3));
				}
				break;
		}

		if(!empty($criteria)) {
			$this->addParam($criteria, $field);
			$this->renderPage = 0;
		}
	}
		
	function doBulkUpdate($filter, $do, $ids=array()) {
		@set_time_limit(600); // 10m
		
		$change_fields = array();
		$custom_fields = array();

		// Make sure we have actions
		if(empty($do))
			return;

		// Make sure we have checked items if we want a checked list
		if(0 == strcasecmp($filter,"checks") && empty($ids))
			return;
			
		if(is_array($do))
		foreach($do as $k => $v) {
			switch($k) {
				// [TODO] Implement actions
				case 'example':
					//$change_fields[DAO_Server::EXAMPLE] = 'some value';
					break;
				default:
					// Custom fields
					if(substr($k,0,3)=="cf_") {
						$custom_fields[substr($k,3)] = $v;
					}
					break;
			}
		}

		$pg = 0;

		if(empty($ids))
		do {
			list($objects,$null) = DAO_Server::search(
				array(),
				$this->getParams(),
				100,
				$pg++,
				SearchFields_Server::ID,
				true,
				false
			);
			$ids = array_merge($ids, array_keys($objects));
			 
		} while(!empty($objects));

		$batch_total = count($ids);
		for($x=0;$x<=$batch_total;$x+=100) {
			$batch_ids = array_slice($ids,$x,100);
			
			DAO_Server::update($batch_ids, $change_fields);

			// Custom Fields
			self::_doBulkSetCustomFields(CerberusContexts::CONTEXT_SERVER, $custom_fields, $batch_ids);
			
			// Scheduled behavior
			if(isset($do['behavior']) && is_array($do['behavior'])) {
				$behavior_id = $do['behavior']['id'];
				@$behavior_when = strtotime($do['behavior']['when']) or time();
				@$behavior_params = isset($do['behavior']['params']) ? $do['behavior']['params'] : array();
				
				if(!empty($batch_ids) && !empty($behavior_id))
				foreach($batch_ids as $batch_id) {
					DAO_ContextScheduledBehavior::create(array(
						DAO_ContextScheduledBehavior::BEHAVIOR_ID => $behavior_id,
						DAO_ContextScheduledBehavior::CONTEXT => CerberusContexts::CONTEXT_SERVER,
						DAO_ContextScheduledBehavior::CONTEXT_ID => $batch_id,
						DAO_ContextScheduledBehavior::RUN_DATE => $behavior_when,
						DAO_ContextScheduledBehavior::VARIABLES_JSON => json_encode($behavior_params),
					));
				}
			}
			
			unset($batch_ids);
		}

		unset($ids);
	}
};
