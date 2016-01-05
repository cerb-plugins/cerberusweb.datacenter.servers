<?php
class Page_Datacenter extends CerberusPageExtension {
	function isVisible() {
		// The current session must be a logged-in worker to use this page.
		if(null == ($worker = CerberusApplication::getActiveWorker()))
			return false;
		return true;
	}
	
	function render() {
	}
	
	function savePeekJsonAction() {
		@$id = DevblocksPlatform::importGPC($_REQUEST['id'],'integer',0);
		@$view_id = DevblocksPlatform::importGPC($_REQUEST['view_id'],'string','');
		@$do_delete = DevblocksPlatform::importGPC($_REQUEST['do_delete'],'integer',0);
		
		$active_worker = CerberusApplication::getActiveWorker();
		
		header('Content-Type: application/json; charset=' . LANG_CHARSET_CODE);
		
		try {
			if($do_delete) { // delete
				if(!$active_worker->is_superuser)
					throw new Exception_DevblocksAjaxValidationError("You don't have permission to delete this record.");
				
				DAO_Server::delete($id);
				
				echo json_encode(array(
					'status' => true,
					'id' => $id,
					'view_id' => $view_id,
				));
				return;
				
			} else { // create | update
				@$name = DevblocksPlatform::importGPC($_REQUEST['name'],'string','');
				@$comment = DevblocksPlatform::importGPC($_REQUEST['comment'], 'string', '');
				
				if(empty($name))
					throw new Exception_DevblocksAjaxValidationError("A 'Name' is required.", 'name');
				
				$fields = array(
					DAO_Server::NAME => $name,
				);
				
				// Create/Update
				if(empty($id)) {
					// Check for dupes
					if(false != DAO_Server::getByName($name))
						throw new Exception_DevblocksAjaxValidationError(sprintf("A server record already exists with the name '%s'.", $name), 'name');
					
					if(false == ($id = DAO_Server::create($fields)))
						throw new Exception_DevblocksAjaxValidationError("There was an error creating the record.");
					
					// View marquee
					if(!empty($id) && !empty($view_id)) {
						C4_AbstractView::setMarqueeContextCreated($view_id, CerberusContexts::CONTEXT_SERVER, $id);
					}
					
				} else {
					DAO_Server::update($id, $fields);
				}
				
				// If we're adding a comment
				if(!empty($comment)) {
					$also_notify_worker_ids = array_keys(CerberusApplication::getWorkersByAtMentionsText($comment));
									
					$fields = array(
						DAO_Comment::CREATED => time(),
						DAO_Comment::CONTEXT => CerberusContexts::CONTEXT_SERVER,
						DAO_Comment::CONTEXT_ID => $id,
						DAO_Comment::COMMENT => $comment,
						DAO_Comment::OWNER_CONTEXT => CerberusContexts::CONTEXT_WORKER,
						DAO_Comment::OWNER_CONTEXT_ID => $active_worker->id,
					);
					$comment_id = DAO_Comment::create($fields, $also_notify_worker_ids);
				}
				
				// Context Link (if given)
				@$link_context = DevblocksPlatform::importGPC($_REQUEST['link_context'],'string','');
				@$link_context_id = DevblocksPlatform::importGPC($_REQUEST['link_context_id'],'integer','');
				if(!empty($id) && !empty($link_context) && !empty($link_context_id)) {
					DAO_ContextLink::setLink(CerberusContexts::CONTEXT_SERVER, $id, $link_context, $link_context_id);
				}
				
				// Custom field saves
				@$field_ids = DevblocksPlatform::importGPC($_POST['field_ids'], 'array', array());
				DAO_CustomFieldValue::handleFormPost(CerberusContexts::CONTEXT_SERVER, $id, $field_ids);
			}
			
			echo json_encode(array(
				'status' => true,
				'id' => $id,
				'label' => $name,
				'view_id' => $view_id,
			));
			return;
		
		} catch (Exception_DevblocksAjaxValidationError $e) {
				echo json_encode(array(
					'status' => false,
					'id' => $id,
					'error' => $e->getMessage(),
					'field' => $e->getFieldName(),
				));
				return;
			
		} catch (Exception $e) {
				echo json_encode(array(
					'status' => false,
					'id' => $id,
					'error' => 'An error occurred.',
				));
				return;
			
		}
	}
	
	function showServerBulkUpdateAction() {
		@$ids = DevblocksPlatform::importGPC($_REQUEST['ids']);
		@$view_id = DevblocksPlatform::importGPC($_REQUEST['view_id']);

		$active_worker = CerberusApplication::getActiveWorker();
		
		$tpl = DevblocksPlatform::getTemplateService();
		$tpl->assign('view_id', $view_id);

		if(!empty($ids)) {
			$id_list = DevblocksPlatform::parseCsvString($ids);
			$tpl->assign('ids', implode(',', $id_list));
		}
		
		// Groups
		$groups = DAO_Group::getAll();
		$tpl->assign('groups', $groups);
		
		// Custom Fields
		$custom_fields = DAO_CustomField::getByContext(CerberusContexts::CONTEXT_SERVER, false);
		$tpl->assign('custom_fields', $custom_fields);
		
		// Macros
		
		$macros = DAO_TriggerEvent::getReadableByActor(
			$active_worker,
			'event.macro.server'
		);
		$tpl->assign('macros', $macros);
		
		$tpl->display('devblocks:cerberusweb.datacenter.servers::datacenter/servers/bulk.tpl');
	}
	
	function doServerBulkUpdateAction() {
		// Filter: whole list or check
		@$filter = DevblocksPlatform::importGPC($_REQUEST['filter'],'string','');
		$ids = array();
		
		// View
		@$view_id = DevblocksPlatform::importGPC($_REQUEST['view_id'],'string');
		$view = C4_AbstractViewLoader::getView($view_id);
		$view->setAutoPersist(false);
		
		// Scheduled behavior
		@$behavior_id = DevblocksPlatform::importGPC($_POST['behavior_id'],'string','');
		@$behavior_when = DevblocksPlatform::importGPC($_POST['behavior_when'],'string','');
		@$behavior_params = DevblocksPlatform::importGPC($_POST['behavior_params'],'array',array());
		
		$do = array();
		
		// Do: Custom fields
		$do = DAO_CustomFieldValue::handleBulkPost($do);

		// Do: Scheduled Behavior
		if(0 != strlen($behavior_id)) {
			$do['behavior'] = array(
				'id' => $behavior_id,
				'when' => $behavior_when,
				'params' => $behavior_params,
			);
		}
		
		switch($filter) {
			// Checked rows
			case 'checks':
				@$ids_str = DevblocksPlatform::importGPC($_REQUEST['ids'],'string');
				$ids = DevblocksPlatform::parseCsvString($ids_str);
				break;
			case 'sample':
				@$sample_size = min(DevblocksPlatform::importGPC($_REQUEST['filter_sample_size'],'integer',0),9999);
				$filter = 'checks';
				$ids = $view->getDataSample($sample_size);
				break;
			default:
				break;
		}
		
		$view->doBulkUpdate($filter, $do, $ids);
		$view->render();
		return;
	}
	
	function viewServersExploreAction() {
		@$view_id = DevblocksPlatform::importGPC($_REQUEST['view_id'],'string');
		
		$active_worker = CerberusApplication::getActiveWorker();
		$url_writer = DevblocksPlatform::getUrlService();
		
		// Generate hash
		$hash = md5($view_id.$active_worker->id.time());
		
		// Loop through view and get IDs
		$view = C4_AbstractViewLoader::getView($view_id);
		$view->setAutoPersist(false);

		// Page start
		@$explore_from = DevblocksPlatform::importGPC($_REQUEST['explore_from'],'integer',0);
		if(empty($explore_from)) {
			$orig_pos = 1+($view->renderPage * $view->renderLimit);
		} else {
			$orig_pos = 1;
		}
		
		$view->renderPage = 0;
		$view->renderLimit = 250;
		$pos = 0;
		
		do {
			$models = array();
			list($results, $total) = $view->getData();

			// Summary row
			if(0==$view->renderPage) {
				$model = new Model_ExplorerSet();
				$model->hash = $hash;
				$model->pos = $pos++;
				$model->params = array(
					'title' => $view->name,
					'created' => time(),
					//'worker_id' => $active_worker->id,
					'total' => $total,
					'return_url' => isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : $url_writer->writeNoProxy('c=search&type=server', true),
//					'toolbar_extension_id' => 'cerberusweb.explorer.toolbar.',
				);
				$models[] = $model;
				
				$view->renderTotal = false; // speed up subsequent pages
			}
			
			if(is_array($results))
			foreach($results as $id => $row) {
				if($id==$explore_from)
					$orig_pos = $pos;
				
				$model = new Model_ExplorerSet();
				$model->hash = $hash;
				$model->pos = $pos++;
				$model->params = array(
					'id' => $id,
					'url' => $url_writer->writeNoProxy(sprintf("c=profiles&type=server&id=%d", $id), true),
				);
				$models[] = $model;
			}
			
			DAO_ExplorerSet::createFromModels($models);
			
			$view->renderPage++;
			
		} while(!empty($results));
		
		DevblocksPlatform::redirect(new DevblocksHttpResponse(array('explore',$hash,$orig_pos)));
	}
};

if(class_exists('Extension_DevblocksEventAction')):
class VaAction_CreateServer extends Extension_DevblocksEventAction {
	function render(Extension_DevblocksEvent $event, Model_TriggerEvent $trigger, $params=array(), $seq=null) {
		$tpl = DevblocksPlatform::getTemplateService();
		$tpl->assign('params', $params);
		
		if(!is_null($seq))
			$tpl->assign('namePrefix', 'action'.$seq);
		
		$event = $trigger->getEvent();
		$values_to_contexts = $event->getValuesContexts($trigger);
		$tpl->assign('values_to_contexts', $values_to_contexts);
		
		// Custom fields
		DevblocksEventHelper::renderActionCreateRecordSetCustomFields(CerberusContexts::CONTEXT_SERVER, $tpl);
		
		// Template
		$tpl->display('devblocks:cerberusweb.datacenter.servers::events/action_create_server.tpl');
	}
	
	function simulate($token, Model_TriggerEvent $trigger, $params, DevblocksDictionaryDelegate $dict) {
		$tpl_builder = DevblocksPlatform::getTemplateBuilder();

		$out = null;
		
		@$name = $tpl_builder->build($params['name'], $dict);
		
		@$notify_worker_ids = DevblocksPlatform::importVar($params['notify_worker_id'],'array',array());
		$notify_worker_ids = DevblocksEventHelper::mergeWorkerVars($notify_worker_ids, $dict);
		
		if(empty($name))
			return "[ERROR] Name is required.";
		
		// Check dupes
		if(false != ($server = DAO_Server::getByName($name))) {
			return sprintf("[ERROR] Name must be unique. A server named '%s' already exists.", $name);
		}
		
		$comment = $tpl_builder->build($params['comment'], $dict);
		
		$out = sprintf(">>> Creating server: %s\n", $name);
		
		// Custom fields
		$out .= DevblocksEventHelper::simulateActionCreateRecordSetCustomFields($params, $dict);
		
		$out .= "\n";
		
		// Comment content
		if(!empty($comment)) {
			$out .= sprintf(">>> Writing comment on server\n\n".
				"%s\n\n",
				$comment
			);
			
			if(!empty($notify_worker_ids) && is_array($notify_worker_ids)) {
				$out .= ">>> Notifying\n";
				foreach($notify_worker_ids as $worker_id) {
					if(null != ($worker = DAO_Worker::get($worker_id))) {
						$out .= ' * ' . $worker->getName() . "\n";
					}
				}
				$out .= "\n";
			}
		}
		
		// Set object variable
		$out .= DevblocksEventHelper::simulateActionCreateRecordSetVariable($params, $dict);
		
		// Run in simulator
		@$run_in_simulator = !empty($params['run_in_simulator']);
		if($run_in_simulator) {
			$this->run($token, $trigger, $params, $dict);
		}
		
		return $out;
	}
	
	function run($token, Model_TriggerEvent $trigger, $params, DevblocksDictionaryDelegate $dict) {
		$tpl_builder = DevblocksPlatform::getTemplateBuilder();
		
		@$name = $tpl_builder->build($params['name'], $dict);

		@$notify_worker_ids = DevblocksPlatform::importVar($params['notify_worker_id'],'array',array());
		$notify_worker_ids = DevblocksEventHelper::mergeWorkerVars($notify_worker_ids, $dict);
		
		$comment = $tpl_builder->build($params['comment'], $dict);
		
		if(empty($name))
			return;
		
		// Dupe check
		if(false != ($server = DAO_Server::getByName($name))) {
			return;
		}
		
		$fields = array(
			DAO_Server::NAME => $name,
		);
			
		if(false == ($server_id = DAO_Server::create($fields)))
			return;
		
		// Custom fields
		DevblocksEventHelper::runActionCreateRecordSetCustomFields(CerberusContexts::CONTEXT_SERVER, $server_id, $params, $dict);
		
		// Comment content
		if(!empty($comment)) {
			$fields = array(
				DAO_Comment::OWNER_CONTEXT => CerberusContexts::CONTEXT_VIRTUAL_ATTENDANT,
				DAO_Comment::OWNER_CONTEXT_ID => $trigger->virtual_attendant_id,
				DAO_Comment::COMMENT => $comment,
				DAO_Comment::CONTEXT => CerberusContexts::CONTEXT_SERVER,
				DAO_Comment::CONTEXT_ID => $server_id,
				DAO_Comment::CREATED => time(),
			);
			DAO_Comment::create($fields, $notify_worker_ids);
		}

		// Set object variable
		DevblocksEventHelper::runActionCreateRecordSetVariable(CerberusContexts::CONTEXT_SERVER, $server_id, $params, $dict);
	}
	
};
endif;

if (class_exists('DevblocksEventListenerExtension')):
class EventListener_Datacenter extends DevblocksEventListenerExtension {
	/**
	 * @param Model_DevblocksEvent $event
	 */
	function handleEvent(Model_DevblocksEvent $event) {
		switch($event->id) {
			case 'cron.maint':
				DAO_Server::maint();
				break;
		}
	}
};
endif;