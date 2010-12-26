<?php
/**
 * A script for returning a feed (currently Atom) of recent changes to a calendar collection
 * @author Leho Kraav <leho@kraav.com>
 * @author Andrew McMillan <andrew@morphoss.com>
 * @license GPL v2 or later
 */
require_once("./always.php");
dbg_error_log( "feed", " User agent: %s", ((isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : "Unfortunately Mulberry and Chandler don't send a 'User-agent' header with their requests :-(")) );
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
  return preg_replace( '@(https?://([-\w\.]+[-\w])+(:\d+)?(/([\w/_\.#-]*(\?\S+)?[^\.\s])?)?)@', '<a href="$1" target="_blank">$1</a>', htmlspecialchars($text) );
}

function caldav_get_feed( $request ) {
  global $c;

  dbg_error_log("feed", "GET method handler");

  require_once("vComponent.php");
  require_once("DAVResource.php");

  $collection = new DAVResource($request->path);
  $collection->NeedPrivilege( array('urn:ietf:params:xml:ns:caldav:read-free-busy','DAV::read') );

  if ( ! $collection->Exists() ) {
    $request->DoResponse( 404, translate("Resource Not Found.") );
  }

  if ( $collection->IsCollection() ) {
    if ( ! $collection->IsCalendar() && !(isset($c->get_includes_subcollections) && $c->get_includes_subcollections) ) {
      $request->DoResponse( 405, translate("Feeds are only supported for calendars at present.") );
    }
    
    $principal = $collection->GetProperty('principal');
    
    /**
     * The CalDAV specification does not define GET on a collection, but typically this is
     * used as a .ics download for the whole collection, which is what we do also.
     */
    $sql = 'SELECT caldav_data, class, caldav_type, calendar_item.user_no, caldav_data.dav_name ';
    $sql .= 'FROM collection INNER JOIN caldav_data USING(collection_id) INNER JOIN calendar_item USING ( dav_id ) WHERE ';
    if ( isset($c->get_includes_subcollections) && $c->get_includes_subcollections ) {
      $sql .= '(collection.dav_name ~ :path_match ';
      $sql .= 'OR collection.collection_id IN (SELECT bound_source_id FROM dav_binding WHERE dav_binding.dav_name ~ :path_match)) ';
      $params = array( ':path_match' => '^'.$request->path );
    }
    else {
      $sql .= 'caldav_data.collection_id = :collection_id ';
      $params = array( ':collection_id' => $collection->resource_id() );
    }
    $sql .= ' ORDER BY caldav_data.modified DESC';
    $sql .= ' LIMIT '.(isset($c->feed_item_limit) ? $c->feed_item_limit : 15);
    $qry = new AwlQuery( $sql, $params );
    if ( !$qry->Exec("GET",__LINE__,__FILE__) ) {
      $request->DoResponse( 500, translate("Database Error") );
    }

    /**
     * Here we are constructing the feed response for this collection, including
     * the timezones that are referred to by the events we have selected.
     * Library used: http://framework.zend.com/manual/en/zend.feed.writer.html
     */
    require_once('AtomFeed.php');
    $feed = new AtomFeed();

    $feed->setTitle('CalDAV Feed: '. $collection->GetProperty('displayname'));
    $url = $c->protocol_server_port . $collection->url();
    $url = preg_replace( '{/$}', '.ics', $url);
    $feed->setLink($url);
    $feed->setFeedLink($c->protocol_server_port_script . $request->path, 'atom');
    $feed->addAuthor(array(
    			'name'  => $principal->GetProperty('displayname'),
    			'email' => $principal->GetProperty('email'),
    			'uri'   => $c->protocol_server_port . $principal->url(),
    ));
    $feed_description = $collection->GetProperty('description');
    if ( isset($feed_description) && $feed_description != '' ) $feed->setDescription($feed_description);

    require_once('RRule-v2.php');

    $need_zones = array();
    $timezones = array();
    while( $event = $qry->Fetch() ) {
      $ical = new vComponent( $event->caldav_data );
      if ( $ical->GetType() != 'VCALENDAR' ) continue;

      $event_data = $ical->GetComponents('VTIMEZONE', false);
      $type = (count($event_data) ? $event_data[0]->GetType() : 'null'); 

      if ( ($type!= 'VEVENT' && $type != 'VTODO' && $type != 'VJOURNAL') ) {
        dbg_error_log( 'feed', 'Skipping peculiar "%s" component in VCALENDAR', $type );
        var_dump($ical);
        continue;
      }
      $is_todo = ($event_data[0]->GetType() == 'VTODO');
      
      $item = $feed->createEntry();
      $uid = $event_data[0]->GetProperty('UID');
      $item->setId( $c->protocol_server_port_script . ConstructURL($event->dav_name).'#'.$uid );

      $dt_created = new RepeatRuleDateTime( $event_data[0]->GetProperty('CREATED') );
      $dt_modified = new RepeatRuleDateTime( $event_data[0]->GetProperty('LAST-MODIFIED') );
      if ( isset($dt_created) ) $item->setDateCreated( $dt_created->epoch() );
      if ( isset($dt_modified) ) $item->setDateModified( $dt_modified->epoch() );

      // According to karora, there are cases where we get multiple VEVENTs (overrides). I'll just stick this (1/x) notifier in here until I get to repeat event processing.
      $summary = $event_data[0]->GetProperty('SUMMARY');
      $p_title = (isset($summary) ? $summary->Value() : translate('No summary')) . ' (1/' . (string)count($event_data) . ')';
      $is_todo ? $p_title = "TODO: " . $p_title : $p_title;
      $item->setTitle($p_title);

      $content = "";

      $dt_start = $event_data[0]->GetProperty('DTSTART');
      if  ( $dt_start != null ) {
        $dt_start = new RepeatRuleDateTime($dt_start);
        $p_time = '<strong>' . translate('Time') . ':</strong> ' . strftime(translate('%F %T'), $dt_start->epoch());

        $dt_end = $event_data[0]->GetProperty('DTEND');
        if  ( $dt_end != null ) {
          $dt_end = new RepeatRuleDateTime($dt_end);
          $p_time .= ' - ' . ( $dt_end->AsDate() == $dt_start->AsDate()
                                   ? strftime(translate('%T'), $dt_end->epoch())
                                   : strftime(translate('%F %T'), $dt_end->epoch())
                              );
        }
        $content .= $p_time;
      }

      $p_location = $event_data[0]->GetProperty('LOCATION');
      if ( $p_location != null )
      $content .= '<br />'
      .'<strong>' . translate('Location') . '</strong>: ' . hyperlink($p_location->Value());

      $p_attach = $event_data[0]->GetProperty('ATTACH');
      if ( $p_attach != null )
      $content .= '<br />'
      .'<strong>' . translate('Attachment') . '</strong>: ' . hyperlink($p_attach->Value());

      $p_url = $event_data[0]->GetProperty('URL');
      if ( $p_url != null )
      $content .= '<br />'
      .'<strong>' . translate('URL') . '</strong>: ' . hyperlink($p_url->Value());

      $p_description = $event_data[0]->GetProperty('DESCRIPTION');
      if ( $p_description != null && $p_description->Value() != '' ) {
        $content .= '<br />'
        .'<br />'
        .'<strong>' . translate('Description') . '</strong>:<br />' . ( nl2br(hyperlink($p_description->Value())) )
        ;
      }

      $item->setContent($content);
      $feed->addEntry($item);
      //break;
    }
    $feed->setDateModified(time());
    $response = $feed->export('atom');
    header( 'Content-Length: '.strlen($response) );
    header( 'Etag: '.$collection->unique_tag() );
    $request->DoResponse( 200, ($request->method == 'HEAD' ? '' : $response), 'text/xml; charset="utf-8"' );
  }
}

if ( $request->method == 'GET' ) {
  caldav_get_feed( $request );
}
else {
  dbg_error_log( 'feed', 'Unhandled request method >>%s<<', $request->method );
  dbg_log_array( 'feed', '_SERVER', $_SERVER, true );
  dbg_error_log( 'feed', 'RAW: %s', str_replace("\n", '',str_replace("\r", '', $request->raw_post)) );
}

$request->DoResponse( 500, translate('The application program does not understand that request.') );

/* vim: set ts=2 sw=2 tw=0 :*/
