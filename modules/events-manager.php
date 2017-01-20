<?php
/*
	* Add Membership Levels box to Events Manager CPTs
	* Hide member events from non-members.
*/

/*
	Add Membership Levels box to Events Manager CPTs
*/
function pmpro_events_page_meta_wrapper()
{
	add_meta_box('pmpro_page_meta', 'Require Membership', 'pmpro_page_meta', 'event', 'side');	
	add_meta_box('pmpro_page_meta', 'Require Membership', 'pmpro_page_meta', 'event-recurring', 'side');	
}
function pmpro_events_init()
{
	if (is_admin())
	{
		add_action('admin_menu', 'pmpro_events_page_meta_wrapper');
	}
}
add_action("init", "pmpro_events_init", 20);

/*
	Add pmpro content message for non-members before event details.
*/
function pmpro_events_manager_em_event_output($event_string, $post, $format, $target) {
	if(function_exists( 'pmpro_hasMembershipLevel' ) && !pmpro_has_membership_access( $post->id ))
	{
		$hasaccess = pmpro_has_membership_access($post->id, NULL, true);
		if(is_array($hasaccess))
		{
			//returned an array to give us the membership level values
			$post_membership_levels_ids = $hasaccess[1];
			$post_membership_levels_names = $hasaccess[2];
			$hasaccess = $hasaccess[0];
		}
		if(empty($post_membership_levels_ids))
			$post_membership_levels_ids = array();
		if(empty($post_membership_levels_names))
			$post_membership_levels_names = array();
	
		 //hide levels which don't allow signups by default
		if(!apply_filters("pmpro_membership_content_filter_disallowed_levels", false, $post_membership_levels_ids, $post_membership_levels_names))
		{
			foreach($post_membership_levels_ids as $key=>$id)
			{
				//does this level allow registrations?
				$level_obj = pmpro_getLevel($id);
				if(!$level_obj->allow_signups)
				{
					unset($post_membership_levels_ids[$key]);
					unset($post_membership_levels_names[$key]);
				}
			}
		}
	
		$pmpro_content_message_pre = '<div class="pmpro_content_message">';
		$pmpro_content_message_post = '</div>';
		$content = '';
		$sr_search = array("!!levels!!", "!!referrer!!");
		$sr_replace = array(pmpro_implodeToEnglish($post_membership_levels_names), urlencode(site_url($_SERVER['REQUEST_URI'])));
		//get the correct message to show at the bottom
		if($current_user->ID)
		{
			//not a member
			$newcontent = apply_filters("pmpro_non_member_text_filter", stripslashes(pmpro_getOption("nonmembertext")));
			$content .= $pmpro_content_message_pre . str_replace($sr_search, $sr_replace, $newcontent) . $pmpro_content_message_post;
		}
		else
		{
			//not logged in!
			$newcontent = apply_filters("pmpro_not_logged_in_text_filter", stripslashes(pmpro_getOption("notloggedintext")));
			$content .= $pmpro_content_message_pre . str_replace($sr_search, $sr_replace, $newcontent) . $pmpro_content_message_post;
		}
		$event_string = $content . $event_string;
		return $event_string;
	}
}
add_action('em_event_output','pmpro_events_manager_em_event_output', 1, 4);

/*
	Hide booking form and replace with the pmpro content message for non-members.
*/
function pmpro_events_manager_output_placeholder($replace, $EM_Event, $result){
	global $wp_query, $wp_rewrite, $post, $current_user;
	switch( $result ){
		case '#_BOOKINGFORM':
			if(empty($hasaccess))
			{
				$replace = '';	
				break;	
			}
	}
	return $replace;
}
add_filter('em_event_output_placeholder','pmpro_events_manager_output_placeholder', 1, 3);

/*
	Hide member events from non-members.
*/
function pmpro_events_manager_template_redirect()
{
	global $post;	
	if(!is_admin() && isset($post->post_type) && ($post->post_type == "event" || $post->post_type == "event-recurring") && !pmpro_has_membership_access())
	{
		wp_redirect(pmpro_url("levels"));
		exit;
	}
}

/*
 	Hide member content from searches.
*/
function pmpro_events_manager_em_events_get($events, $args)
{
	//don't do anything in the admin
	if(is_admin())
		return $events;
 
	//which events are restricted
	global $wpdb, $current_user;	
	$sqlQuery = "SELECT DISTINCT(mp.page_id) FROM $wpdb->pmpro_memberships_pages mp LEFT JOIN $wpdb->posts p ON mp.page_id = p.ID WHERE p.post_type IN('event', 'event-recurring') ";
	if(!empty($current_user->membership_level->id))
		$sqlQuery .= " AND mp.membership_id <> '" . $current_user->membership_level->id . "' ";
	$restricted_events = $wpdb->get_col($sqlQuery);
	
	//remove restricted events	
	$recurrence_events = array();
	$newevents = array();
	foreach($events as $event)
	{
		//if the event is recurring, get the post id of it's parent
		if(!empty($event->recurrence_id) && empty($recurrence_events[$event->recurrence_id]))
		{
			//set post id for recurrence event in the recurrence events array
			$recurrence_events[$event->recurrence_id] = $wpdb->get_var("SELECT post_id FROM " . $wpdb->prefix . "em_events WHERE event_id = '" . $event->recurrence_id . "' LIMIT 1");						
		}
		
		if(!in_array($event->post_id, $restricted_events) && (empty($recurrence_events[$event->recurrence_id]) || !in_array($recurrence_events[$event->recurrence_id], $restricted_events)))
			$newevents[] = $event;
	}
	
	return $newevents;
}

/*
	Filter searches and redirect sinle event page if PMPro Option to filter is set.
*/
$filterqueries = pmpro_getOption("filterqueries");
if(!empty($filterqueries))
{
	add_filter('em_events_get','pmpro_events_manager_em_events_get', 10, 2);
	add_action('wp', 'pmpro_events_manager_template_redirect');
}