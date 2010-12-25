<?php
require_once("./always.php");
dbg_error_log( "rss", " User agent: %s", ((isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : "Unfortunately Mulberry and Chandler don't send a 'User-agent' header with their requests :-(")) );
dbg_log_array( "headers", '_SERVER', $_SERVER, true );

require_once("HTTPAuthSession.php");
$session = new HTTPAuthSession();

require_once('CalDAVRequest.php');
$request = new CalDAVRequest();

/**
* Function for creating anchor links out of plain text.
* Source: http://stackoverflow.com/questions/1960461/convert-plain-text-hyperlinks-into-html-hyperlinks-in-php
*/ 
function hyperlink( $text ) {
  return preg_replace( '@(https?://([-\w\.]+[-\w])+(:\d+)?(/([\w/_\.#-]*(\?\S+)?[^\.\s])?)?)@', '<a href="$1" target="_blank">$1</a>', $text );
}

function caldav_get_rss( $request ) {
	dbg_error_log("rss", "GET method handler");

	require_once("iCalendar.php");
	require_once("DAVResource.php");

	$dav_resource = new DAVResource($request->path);
	$dav_resource->NeedPrivilege( array('urn:ietf:params:xml:ns:caldav:read-free-busy','DAV::read') );

	if ( ! $dav_resource->Exists() ) {
	  $request->DoResponse( 404, translate("Resource Not Found.") );
	}

  if ( $dav_resource->IsCollection() ) {
    if ( ! $dav_resource->IsCalendar() && !(isset($c->get_includes_subcollections) && $c->get_includes_subcollections) ) {
      /** RFC2616 says we must send an Allow header if we send a 405 */
      header("Allow: PROPFIND,PROPPATCH,OPTIONS,MKCOL,REPORT,DELETE");
      $request->DoResponse( 405, translate("GET requests on collections are only supported for calendars.") );
    }

	  /**
	  * The CalDAV specification does not define GET on a collection, but typically this is
	  * used as a .ics download for the whole collection, which is what we do also.
	  */
	  $sql = 'SELECT caldav_data, class, caldav_type, calendar_item.user_no, logged_user ';
	  $sql .= 'FROM collection INNER JOIN caldav_data USING(collection_id) INNER JOIN calendar_item USING ( dav_id ) WHERE ';
	  if ( isset($c->get_includes_subcollections) && $c->get_includes_subcollections ) {
      $sql .= '(collection.dav_name ~ :path_match ';
      $sql .= 'OR collection.collection_id IN (SELECT bound_source_id FROM dav_binding WHERE dav_binding.dav_name ~ :path_match)) ';
      $params = array( ':path_match' => '^'.$request->path );
	  }
	  else {
      $sql .= 'caldav_data.collection_id = :collection_id ';
      $params = array( ':collection_id' => $dav_resource->resource_id() );
	  }
	  if ( isset($c->strict_result_ordering) && $c->strict_result_ordering ) $sql .= ' ORDER BY dav_id';
      $qry = new AwlQuery( $sql, $params );
      if ( !$qry->Exec("GET",__LINE__,__FILE__) ) {
      $request->DoResponse( 500, translate("Database Error") );
	  }
    
	  /**
	  * Here we are constructing the RSS feed response for this collection, including
	  * the timezones that are referred to by the events we have selected.
	  * Library used: http://framework.zend.com/manual/en/zend.feed.writer.html
	  */
    require_once('Zend/Feed/Writer/Feed.php');
    $feed = new Zend_Feed_Writer_Feed;

    $feed->setTitle('CalDAV RSS Feed: '. $dav_resource->GetProperty('displayname'));
    $feed->setLink('http://www.google.com');
    $feed->setDescription('This is test of creating a Zend Feed.');

    require_once('RRule-v2.php');

    $need_zones = array();
    $timezones = array();
    while( $event = $qry->Fetch() ) {
      $is_todo = false;
      $ical = new iCalComponent( $event->caldav_data );
      $event_data = $ical->GetComponents( 'VEVENT', true );
      if ( !count($event_data) ) {
        $event_data = $ical->GetComponents( 'VTODO', true );
        if ( !count($event_data) ) {
          echo "wack event_data found";
          dbg_log_array( 'rss', 'ical', $ical, true );
        }
        else {
          $is_todo = true;
        }
      }

      $item = $feed->createEntry();
        $item->setId( $event_data[0]->GetPValue('UID') );

        $dt_created = new RepeatRuleDateTime( $event_data[0]->GetPValue('CREATED') );
        $dt_modified = new RepeatRuleDateTime( $event_data[0]->GetPValue('LAST-MODIFIED') );
        if ( $dt_created->isDate() ) $item->setDateCreated( $dt_created->epoch() );
        if ( $dt_modified->isDate() ) $item->setDateModified( $dt_modified->epoch() );

        // According to karora, there are cases where we get multiple VEVENTs (repeats). I'll just stick this (1/x) notifier in here until I get to repeat event processing. 
        $p_title = $event_data[0]->GetPValue('SUMMARY') . ' (1/' . (string)count($event_data) . ')';
        $is_todo ? $p_title = "TODO: " . $p_title : $p_title;
        $item->setTitle($p_title);

        $description = "";

        $dt_start = $event_data[0]->GetPValue('DTSTART');
        if  ( $dt_start != null ) {
          $dt_start = new RepeatRuleDateTime($dt_start);
          $p_time = '<strong>' . translate('Time') . ':</strong> ' . $dt_start->format(translate('Y-m-d H:i:s'));

          $dt_end = $event_data[0]->GetPValue('DTEND'); 
          if  ( $dt_end != null ) {
            $dt_end = new RepeatRuleDateTime($dt_end);
            $p_time .= ' - ';
            $dt_end->AsDate() == $dt_start->AsDate() ? $p_time .= $dt_end->format(translate('H:i:s')) : $p_time .= $dt_end->format(translate('Y-m-d H:i:s'));
          }
          $description .= $p_time;
        }

        $p_location = $event_data[0]->GetPValue('LOCATION');
        if ( $p_location != null )
          $description .= '<br />'
            .'<strong>' . translate('Location') . '</strong>: ' . hyperlink($p_location);

        $p_attach = $event_data[0]->GetPValue('ATTACH');
        if ( $p_attach != null )
          $description .= '<br />'
            .'<strong>' . translate('Attachment') . '</strong>: ' . hyperlink($p_attach);

        $p_url = $event_data[0]->GetPValue('URL');
        if ( $p_url != null )
          $description .= '<br />'
            .'<strong>' . translate('URL') . '</strong>: ' . hyperlink($p_url);

        $p_description = $event_data[0]->GetPValue('DESCRIPTION');
        if ( $p_description != null ) {
          $description .= '<br />'
            .'<br />'
            .'<strong>' . translate('Description') . '</strong>:<br />' . ( nl2br(hyperlink($p_description)) )
          ;
        }
        
        $item->setDescription($description);
      $feed->addEntry($item);
    }

    $response = $feed->export('rss');
	  header( 'Content-Length: '.strlen($response) );
	  header( 'Etag: '.$dav_resource->unique_tag() );
	  $request->DoResponse( 200, ($request->method == 'HEAD' ? '' : $response), 'text/xml; charset="utf-8"' );
	}
}

if ( $request->method == 'GET' ) {
	caldav_get_rss( $request );
}
else {
    dbg_error_log( 'rss', 'Unhandled request method >>%s<<', $request->method );
    dbg_log_array( 'rss', '_SERVER', $_SERVER, true );
    dbg_error_log( 'rss', 'RAW: %s', str_replace("\n", '',str_replace("\r", '', $request->raw_post)) );
}

$request->DoResponse( 500, translate('The application program does not understand that request.') );

/* vim: set ts=2 sw=2 tw=0 :*/
