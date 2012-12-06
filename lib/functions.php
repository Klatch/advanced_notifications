<?php

	/**
	 * 
	 * Checks if the given $entity is registered for notifications by 
	 * register_notification_object()
	 * 
	 * @param ElggEntity $entity
	 * @param bool $subject => return the subject string (default false)
	 * @return boolean | string
	 */
	function advanced_notifications_is_registered_notification_entity(ElggEntity $entity, $subject = false){
		$result = false;
		
		if(!empty($entity) && ($entity instanceof ElggEntity)){
			if(!($type = $entity->getType())){
				$type = "__BLANK__";
			}
			
			if(!($subtype = $entity->getSubtype())){
				$subtype = "__BLANK__";
			}
			
			// get the registered entity -> type/subtype
			$notifications = elgg_get_config("register_objects");
				
			if(!empty($notifications) && is_array($notifications)){
				if(isset($notifications[$type]) && isset($notifications[$type][$subtype])){
					if($subject){
						$result = $notifications[$type][$subtype];
					} else {
						$result = true;
					}
				}
			}
		}
		
		return $result;
	}
	
	function advanced_notifications_is_registered_notification_annotation(ElggAnnotation $annotation){
		$result = false;
		
		if(!empty($annotation) && ($annotation instanceof ElggAnnotation)){
			$supported_annotations = array(
				"group_topic_post"
			);
			
			if(in_array($annotation->name, $supported_annotations)){
				$result = true;
			}
		}
		
		return $result;
	}

	/**
	 * 
	 * Start a new commandline php process to sent out the notifications
	 * 
	 * @param array $options
	 */
	function advanced_notifications_start_commandline($options = array()){
		// set some default options for the commandline
		$defaults = array(
			"secret" => advanced_notifications_generate_secret(),
			"host" => $_SERVER["HTTP_HOST"],
			"memory_limit" => ini_get("memory_limit"),
			"session_id" => session_id()
		);
		
		// is this a secure site
		if(isset($_SERVER["HTTPS"])){
			$defaults["https"] = $_SERVER["HTTPS"];
		}
		
		// make sure $options is an array
		if(!empty($options) && !is_array($options)){
			$options = array($options);
		}
		
		// merge defaults with supplied options
		$options = array_merge($defaults, $options);
		
		// prepare to call the commandline
		$script_location = dirname(dirname(__FILE__)) . "/procedures/cli.php";
		$query_string = http_build_query($options, "", " ");
		
		if(PHP_OS === "WINNT"){
			pclose(popen("start /B php " . $script_location . " " . $query_string, "r"));
		} else {
			exec("php " . $script_location . " " . $query_string . " > /dev/null &");
		}
	}
	
	/**
	 * Generate a secret to be used in calling the commandline
	 * 
	 * @return string
	 */
	function advanced_notifications_generate_secret(){
		static $result;
	
		if(!isset($result)){
			$site_secret = get_site_secret();
			$plugin = elgg_get_plugin_from_id("advanced_notifications");
				
			$result = md5($plugin->getGUID() . $site_secret . $plugin->time_created);
		}
	
		return $result;
	}
	
	/**
	 * Validate the secret provided to the commandline
	 * 
	 * @param string $secret
	 * @return boolean
	 */
	function advanced_notifications_validate_secret($secret){
		$result = false;
	
		if(!empty($secret)){
			if($correct_secret = advanced_notifications_generate_secret()){
				if($secret === $correct_secret){
					$result = true;
				}
			}
		}
	
		return $result;
	}
	
	/**
	 * Sent out the notifications for the provided entity(_guid)
	 * 
	 * @param int $guid
	 * @param string $event
	 */
	function advanced_notifications_entity_notification($guid, $event){
		global $NOTIFICATION_HANDLERS;
		global $DB_QUERY_CACHE;
		
		// get the entity to notify
		if($entity = get_entity($guid)){
			// check if the entity isn't private, this shouldn't happen as the commandline should have prevented this
			if($entity->access_id != ACCESS_PRIVATE){
				// check if this is a notifiable entity type/subtype, this also shouldn't happen see above
				if($default_subject = advanced_notifications_is_registered_notification_entity($entity, true)){
					// let's prepare for sending
					$default_message = $default_subject . ": " . $entity->getGUID();
					
					if(!empty($NOTIFICATION_HANDLERS) && is_array($NOTIFICATION_HANDLERS)){
						// this could take a long time, especialy with large groups
						set_time_limit(0);
						
						// prepare the options to get the interested users
						$options = array(
							"type" => "user",
							"site_guids" => ELGG_ENTITIES_ANY_VALUE,
							"limit" => false,
							"joins" => array("JOIN " . elgg_get_config("dbprefix") . "users_entity ue ON e.guid = ue.guid"),
							"wheres" => array("(ue.banned = 'no')"), // banned users don't need to be notified
							"relationship_guid" => $entity->getContainerGUID(),
							"inverse_relationship" => true,
							"callback" => "advanced_notifications_row_to_guid"
						);
						// if we have a logged in user, don't notify him/her
						if($logged_in_user_guid = elgg_get_logged_in_user_guid()){
							$options["wheres"][] = "(e.guid <> " . $logged_in_user_guid . ")";
						}
						
						// process the different notification handlers
						foreach($NOTIFICATION_HANDLERS as $method => $dummy){
							// get the interested users for the entity
							$options["relationship"] = "notify" . $method;
							
							if($user_guids = elgg_get_entities_from_relationship($options)){
								foreach($user_guids as $user_guid){
									// fetch the user entity to process
									if($user = get_user($user_guid)){
										// check if the user has access to the entity
										if(has_access_to_entity($entity, $user)){
											// trigger a hook to make a custom message
											$message = elgg_trigger_plugin_hook("notify:entity:message", $entity->getType(), array(
												"entity" => $entity,
												"to_entity" => $user,
												"method" => $method), $default_message);
											// check if the hook made a correct message
											if(empty($message) && $message !== false){
												// the hook did it incorrect, so reset the message
												$message = $default_message;
											}
											
											// this is new, trigger a hook to make a custom subject
											$subject = elgg_trigger_plugin_hook("notify:entity:subject", $entity->getType(), array(
												"entity" => $entity,
												"to_entity" => $user,
												"method" => $method), $default_subject);
											// check if the hook made a correct subject
											if(empty($subject)){
												// the hook did it incorrect, so reset the subject
												$subject = $default_subject;
											}
											
											// if the hook returnd false, don't sent a notification
											if($message !== false){
												notify_user($user->getGUID(), $entity->getContainerGUID(), $subject, $message, null, $method);
											}
										}
									}
									
									// cleanup some of the caches
									$DB_QUERY_CACHE->clear();
									invalidate_cache_for_entity($user_guid);
									
									unset($user);
								}
							}
							
							// some small cleanup
							unset($user_guids);
						}
					}
				}
			}
		}
	}
	
	/**
	 * Wrapper function to fetch the object out of the annotation to pass on the to object notification
	 * 
	 * @uses advanced_notifications_object_notification()
	 * @param int $id
	 * @param string $event
	 */
	function advanced_notifications_annotation_notification($id, $event){
		global $NOTIFICATION_HANDLERS;
		global $DB_QUERY_CACHE;
		
		// get the annotation
		if($annotation = elgg_get_annotation_from_id($id)){
			// are notifications on this annotation allowed
			if(advanced_notifications_is_registered_notification_annotation($annotation)){
				// get the entity the annotation was made on
				$entity = $annotation->getEntity();
				
				// get the owner of the annotation
				$owner = $annotation->getOwnerEntity();
				
				if(!empty($entity) && !empty($owner)){
					// make sure the entity isn't a PRIVATE entity, this shouldn't happed as the commandline shouldn't be called
					if($entity->access_id != ACCESS_PRIVATE){
						// is the entity a registered entity type/subtype, this shouldn't happen see above
						if($default_subject = advanced_notifications_is_registered_notification_entity($entity, true)){
							// prepare the message to sent
							$default_message = $default_subject . ": " . $entity->getURL();
							
							if(!empty($NOTIFICATION_HANDLERS) && is_array($NOTIFICATION_HANDLERS)){
								// this could take a long time, especialy with large groups
								set_time_limit(0);
								
								// prepare options to get the interested users
								$options = array(
									"type" => "user",
									"site_guids" => ELGG_ENTITIES_ANY_VALUE,
									"limit" => false,
									"joins" => array("JOIN " . elgg_get_config("dbprefix") . "users_entity ue ON e.guid = ue.guid"),
									"wheres" => array(
										"(ue.banned = 'no')", // banned users don't need to be notified
										"(e.guid <> " . $owner->getGUID() . ")"
									),
									"relationship_guid" => $entity->getContainerGUID(),
									"inverse_relationship" => true,
									"callback" => "advanced_notifications_row_to_guid"
								);
								
								foreach($NOTIFICATION_HANDLERS as $method => $dummy){
									// get the interested users for the entity
									$options["relationship"] = "notify" . $method;
										
									if($user_guids = elgg_get_entities_from_relationship($options)){
										foreach($user_guids as $user_guid){
											// fetch the user entity to process
											if($user = get_user($user_guid)){
												// check if the user has access to the entity
												if(has_access_to_entity($entity, $user)){
													// trigger a hook to make a custom message
													$message = elgg_trigger_plugin_hook("notify:annotation:message", $annotation->getSubtype(), array(
														"annotation" => $annotation,
														"to_entity" => $user,
														"method" => $method), $default_message);
													// check if the hook made a correct message
													if(empty($message) && $message !== false){
														// the hook did it incorrect, so reset the message
														$message = $default_message;
													}
														
													// this is new, trigger a hook to make a custom subject
													$subject = elgg_trigger_plugin_hook("notify:annotation:subject", $annotation->getSubtype(), array(
														"annotation" => $annotation,
														"to_entity" => $user,
														"method" => $method), $default_subject);
													// check if the hook made a correct subject
													if(empty($subject)){
														// the hook did it incorrect, so reset the subject
														$subject = $default_subject;
													}
														
													// if the hook returnd false, don't sent a notification
													if($message !== false){
														notify_user($user->getGUID(), $entity->getContainerGUID(), $subject, $message, null, $method);
													}
												}
											}
												
											// cleanup some of the caches
											$DB_QUERY_CACHE->clear();
											invalidate_cache_for_entity($user_guid);
												
											unset($user);
										}
									}
										
									// some small cleanup
									unset($user_guids);
								}
							}
						}
					}
				}
			}
		}
	}
	
	function advanced_notifications_row_to_guid($row){
		return (int) $row->guid;
	}