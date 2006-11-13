<?php
 $title = "Installation";
 include("inc/page-header.php");
?>
<h1>Before Starting</h1>

<h2>Debian Users</h2>
<p>Ideally you will be running a recent Debian release and will
be able to add:</p>
<pre>
deb http://debian.mcmillan.net.nz/debian unstable awm
</pre>
<p>to your <code>/etc/apt/sources.list</code>.  Once you have done that you
can use <code>apt-get</code> or <code>synaptic</code> or some other equivalent package
manager to fetch and install <code>rscds</code> and all the dependencies.</p>

<p>Skip to the "Database Setup" part if you have done that already.</p>


<h2>Other Linux Users</h2>

<p>You will need to download the latest versions of the <code>rscds</code> and <code>awl</code> packages
from the <a href="http://sourceforge.net/project/showfiles.php?group_id=179845">sourceforge download page for rscds</a>.</p>
<p>You will need to untar these.  Preferably you will untar them from within the "<code>/usr/share</code>" directory and everything
will be in it's expected location (well, except the docs, but it will at least be tidy and everything will be in one place).</p>

<p>I would like to hear from non-Debian users regarding things I might have missed, or things you have
learned about the system, so please post a message on the forums, or
e-mail <a href="http://andrew.mcmillan.net.nz/">me</a> or something.</p>

<h2>Non-Linux Users</h2>
<p>I would really like to hear from you.  As far as I can see there is no reason why this
can't all work on FreeBSD, Microsoft Windows, VMS, Mac OS or whatever else, as long as the
pre-requisites are able to be installed.</p>
<p>For Unix and unix-like operating systems the "Other Linux Users" instructions are likely
to be reasonably close to what you need.  For other systems everything will need some
adjustment, and in particular some of the path name and shell expectations coded into
the database creation scripts are likely to need love.</p>
<p>I'm available to answer questions, anyway :-)</p>

<h1>Pre-requisites</h1>

<p>RSCDS depends on a number of things.  Firstly, it depends
on Andrew's Web Libraries (AWL) which is a set of useful
PHP functions and objects written by Andrew McMillan over
a number of years.</p>

<p>The following other software is also needed:</p>
<ul>
  <li>Apache: 1.3.x or 2.x.x</li>
  <li>PHP: 4.3 or greater, including PHP5</li>
  <li>PostgreSQL: 7.4 or greater</li>
</ul>

<p>The PostgreSQL database may be installed on a server other
than the web server, and that kind of situation is recommended
if you want to increase the security or scalability of your
installation.</p>

<p>Since the CalDAV store takes over a significant amount of path
hierarchy, it is designed to be installed in it's own virtual
host.  If you want it to operate within the web root of some
other application I will happily accept patches to make it do
that, but I am pretty sure it won't work that way out of the
box.</p>


<h1>Database Setup</h1>

<p>On your database server you will need to create a user called
'general' which should not be able to create databases or users,
and which will be granted minimum privileges for the application.</p>
<pre>
createuser --no-createdb --no-createrole general
</pre>

<p>You may need to become the 'postgres' user to do this, in which case
you will need to be the postgres user to create the database as well.
For example:</p>
<pre>
su postgres -c createuser --no-createdb --no-createrole general
</pre>

<p>To create the database itself, run the script:</p>
<pre>
dba/create_database.sh
</pre>
<p>Note that this script calls the AWL database scripts as part
of itself and it expects them to be located in /usr/share/awl/dba
which might be a reasonable place, but it might not be where you
have put them.</p>

<p>Similarly to creating the user, this script also expects to be
running as a user who has rights to create a new database, so you
may need to do this as the "postgres" user, for example:</p>
<pre>
su postgres -c /usr/share/rscds/dba/create-database.sh
</pre>

<p>Once your database has been created, you may also need to
edit your pg_hba.conf file in order to grant the application
access to the database as the 'general' user.</p>

<h1>Apache VHost Configuration</h1>

<p>Your Apache instance needs to be configured for Virtual Hosts.  If
this is not already the case you may want to read some documentation
about that, and you most likely will want to ensure that any existing
site becomes the **default** virtual host, with RSCDS only being a
single virtual host.</p>

<p>I use a Virtual Host stanza like this:</p>
<pre>
#
# Virtual Host def for Debian packaged RSCDS
&lt;VirtualHost 123.4.56.78 >
  DocumentRoot /usr/share/rscds/htdocs
  DirectoryIndex index.php index.html
  ServerName rscds.example.net
  ServerAlias calendar.example.net
  Alias /images/ /usr/share/rscds/htdocs/images/
  php_value include_path /usr/share/rscds/inc:/usr/share/awl/inc
  php_value magic_quotes_gpc 0
  php_value register_globals 1
&lt;/VirtualHost>
</pre>

<p>Replace 123.4.56.78 with your own IP address, of course (you can
use a name, but your webserver may fail on restart if DNS happens
to be borked at that time).</p>

<p>At this point it is necessary to have register_globals enabled. All
variables are sanitised before use, but some routines do assume
this is turned on.</p>

<p>The various paths and names need to be changed to reflect your
own installation, although those are the recommended locations
for the various pieces of the code (and are standard if you
installed from a package.</p>

<p>Once your VHost is installed an working correctly, you should be
able to browse to that address and see a page telling you that
you need to configure RSCDS.</p>



<h1>RSCDS Configuration</h1>

<p>The RSCDS configuration generally resides in /etc/rscds/&lt;domain&gt;-conf.php
and is a regular PHP file which sets (or overrides) some specific variables.</p>

<pre>
&lt;?php
//  $c->domainname = "calendar.example.net";
//  $c->sysabbr     = 'rscds';
//  $c->admin_email = 'admin@example.net';
//  $c->system_name = "Really Simple CalDAV Store";
//  $c->collections_always_exist = true;
//  $c->default_locale = en_NZ.UTF-8;

//  $c->pg_connect[] = 'dbname=caldav port=5433 user=general';
  $c->pg_connect[] = 'dbname=caldav port=5432 user=general';

?>
</pre>

<p>Multiple values may be specified for the PostgreSQL connect string,
so that you can (e.g.) use PGPool to cache the database connection
but fall back to a raw database connection if it is not running.</p>

<p>The "collections_always_exist" value defines whether a MKCALENDAR
command is needed to create a calendar collection before calendar
resources can be stored in it.  You will want to leave this to the
default (true) if people will be using Evolution or Sunbird /
Lightning against this because that software does not support the
creation of calendar collections.</p>

<p>You should set the 'domainname' and 'admin_email' as they are used
within the system for constructing URLs, and for notifying some
kinds of events.</p>

<p>If you are in a non-English locale, you can set the default_locale
configuration to one of the supported locales.</p>

<h1>Supported Locales</h1>
<p>At present the following locales are supported:</p>
<ul>
<li>English (New Zealand)</li>
<li>Spanish (Argentinian)</li>
<li>German</li>
<li>French</li>
<li>Russian</li>
</ul>

<p>In addition the Argentinian Spanish translation is also configured as base Spanish
and Mexican Spanish since although I don't have translations for those yet I expect I
can find someone to do them for me... (you know who you are :-)</p>

<p>If you want locale support you probably know more about configuring it than me, but
at this stage it should be noted that all translations are UTF-8, and pages are
served as UTF-8, so you will need to ensure that the UTF-8 versions of these locales
are supported on your system.</p>


<h1>Completed?</h1>

<p>If all is going well you should now be able to browse to the admin
pages and log in as 'admin' (the password is the bit after the '**'
in the 'password' field of the 'usr' table so:</p>
<pre>
psql rscds -c 'select username, password from usr;'
</pre>

<p>should show you a list.  Note that once you change a password it
won't be readable in this way - only the initial configuration
leaves passwords readable like this for security reasons.</p>

<p>If all is working then you should be ready to configure a client
to use this, and the docs for that are elsewhere.</p>

<?php
 include("inc/page-footer.php");
?>