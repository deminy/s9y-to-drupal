<?php
/**
 * Import Serendipity blog posts along with comments and tags into Drupal.
 *
 * The process is done in three steps:
 *     1). pull out all Serendipity comments from database.
 *     2). get blog entries from a S9Y RSS feed.
 *     3). for each Serendipity blog entry:
 *         3.1). store it in Drupal as a node.
 *         3.2). store category and tags as Drupal tags.
 *         3.3). store comments into Drupal.
 *
 * For known lmitations and possible issues, please check file 'README.md'.
 *
 * @author Demin Yin <deminy@deminy.net>
 */

if (is_dir($vendor = __DIR__ . '/vendor')) {
    require_once $vendor . '/autoload.php';
} else {
    die(
        'You must set up the project dependencies, run the following commands:' . PHP_EOL . 
        'curl -s http://getcomposer.org/installer | php && php composer.phar install' . PHP_EOL
    );
}

use Zend\Config\Reader\Ini, Zend\Feed\Reader\Reader, Zend\Http\Client;


/**
 * Get Drupal term ID based on given term name in given vocabulary.
 *
 * @param string $name Term name.
 * @param string $vocabulary Machine name of given vocabulary.
 * @return int Term ID.
 * @throws \Exception
 */
function getDrupalTermId($name, $vocabulary) {
    static $cachedTerms = array();

    if (! isset($cachedTerms[$vocabulary][$term])) {
        if (! array_key_exists($vocabulary, $cachedTerms)) {
                $cachedTerms[$vocabulary] = array();
        }
        $objVocabulary = taxonomy_vocabulary_machine_name_load($vocabulary);

        if ($objVocabulary instanceof stdClass) {
            $terms = taxonomy_get_term_by_name($name, $vocabulary);
            switch (count($terms)) {
                case 0:
                    $term = (object) array(
                        'vid' => $objVocabulary->vid,
                        'name' => $name,
                    );
                    taxonomy_term_save($term);
                    $cachedTerms[$vocabulary][$term] = $term->tid;
                    break;
                case 1:
                    $term = reset($terms);
                    break;
                default:
                    throw new \Exception('Only one term object should be returned back for any given term name');
                    break;
            }

            return $term->tid;
        } else {
            throw new \Exception(
                sprintf(
                    'Before running this script, vocabulary "%s" (machine name) must be manually created and ' 
                    . 'added as a field of selected content type.',
                    $vocabulary
                )
            );
        }
    } else {
        return $cachedTerms[$vocabulary][$term];
    }
}


if (! is_file($fileConfig = __DIR__ . '/config.ini')) {
    die('You must have file config.ini set up. Please use file config.ini.dist as an example.' . PHP_EOL);
}

// Load and store configurations from config.ini.
$ini = new Ini();
$config = $ini->fromFile($fileConfig);

// Remote trailing slash from Drupal root directory.
if (substr($config['drupal']['rootDir'], -1) == '/') {
    $drupalRootDir = substr($config['drupal']['rootDir'], 0, -1);
} else {
    $drupalRootDir = $config['drupal']['rootDir'];
}

$_GET = $_POST = $_REQUEST = $_COOKIE = array();
$_SERVER = array(
    'HTTP_HOST'            => $config['drupal']['domain'],
    'HTTP_USER_AGENT'      => null,
    'HTTP_ACCEPT'          => null,
    'HTTP_ACCEPT_LANGUAGE' => null,
    'HTTP_ACCEPT_ENCODING' => null,
    'HTTP_ACCEPT_CHARSET'  => null,
    'HTTP_KEEP_ALIVE'      => null,
    'HTTP_CONNECTION'      => null,
    'PATH'                 => null,
    'SERVER_SIGNATURE'     => null,
    'SERVER_SOFTWARE'      => null,
    'SERVER_NAME'          => $config['drupal']['domain'],
    'SERVER_ADDR'          => '127.0.0.1',
    'SERVER_PORT'          => 80,
    'REMOTE_ADDR'          => null,
    'DOCUMENT_ROOT'        => $drupalRootDir,
    'SERVER_ADMIN'         => null,
    'SCRIPT_FILENAME'      => $drupalRootDir . '/index.php',
    'REMOTE_PORT'          => null,
    'GATEWAY_INTERFACE'    => 'CGI/1.1',
    'SERVER_PROTOCOL'      => 'HTTP/1.1',
    'REQUEST_METHOD'       => 'GET',
    'QUERY_STRING'         => null,
    'REQUEST_URI'          => '/index.php',
    'SCRIPT_NAME'          => '/index.php',
    'PHP_SELF'             => '/index.php',
    'REQUEST_TIME'         => null,
);

chdir($_SERVER['DOCUMENT_ROOT']);
define('DRUPAL_ROOT', getcwd());

require_once './includes/bootstrap.inc';
drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);
drupal_bootstrap(DRUPAL_BOOTSTRAP_VARIABLES);
error_reporting(E_ALL);

/**
 * Step 1: pull out all Serendipity comments from database.
 */
Database::addConnectionInfo('s9y', 'default', $config['s9y']['db']);
db_set_active('s9y'); // switch to the Serendipity connection to pull out Serendipity comments

if (!empty($config['s9y']['db']['charset'])) {
    db_query(sprintf("SET NAMES '%s'", $config['s9y']['db']['charset']));
}

$ignoreLinkbacks = !isset($config['s9y']['ignoreLinkbacks']) || !empty($config['s9y']['ignoreLinkbacks']);
// Comments need to be sorted properly so that we can easily rebuid hierarchy stucture of them in Drupal.
if ($ignoreLinkbacks) {
    $sql = 'SELECT * FROM {comments} WHERE type = \'NORMAL\' ORDER BY parent_id ASC, id ASC';
} else {
    $sql = 'SELECT * FROM {comments} ORDER BY parent_id ASC, id ASC';
}
$s9yComments = array();
foreach (db_query($sql) as $result) {
    if (! array_key_exists($result->entry_id, $s9yComments)) {
        $s9yComments[$result->entry_id] = array();
    }

    $s9yComments[$result->entry_id][] = $result;
}

db_set_active(); // set back to the default Drupal connection


$blogType = ! empty($config['drupal']['type']) ? $config['drupal']['type'] : 'blog';
$blogFormat = ! empty($config['drupal']['format']) ? $config['drupal']['format'] : 'filtered_html';
$emptySummary = isset($config['drupal']['emptySummary']) ? (boolean) $config['drupal']['emptySummary'] : true;


/**
 * Step 2: get blog entries from a S9Y RSS feed.
 */
$s9yBaseUrl = $config['s9y']['baseUrl'] . ((substr($config['s9y']['baseUrl'], -1) == '/') ? '' : '/');
$s9yRssUrl  = $s9yBaseUrl . 'rss.php?version=2.0' . (!empty($config['debug']) ? '' : '&all=1');
/**
 * Ideally we should be able to use class Zend\Feed\Reader\Reader to import data from the RSS feed directly
 * ("Reader::import($url);"), however, when dealing with East Asian characters, the Zend\Http\Client::getBody() 
 * method would remove first few characters out from the response before returning it back.
 *
 * Because of this, we have to use the Client class directly, and call method Client::getContent() instead of
 * method Client::getBody().
 */
$client = new Client($s9yRssUrl);
/**
 * To avoid any possible issues caused by calling method $response->getBody() (which could cause first few
 * characters in the body part removed), we explicitly state that response data shouldn't be compressed.
 */
$client->setHeaders(array('Accept-Encoding' => 'identity'));
$response = $client->send();
/**
 * Here we use method $response->getContent() instead of method $response->getBody(). Please check previous
 * comments for the reason.
 */
$data = $response->getContent();

/**
 * Step 3: for each Serendipity blog entry:
 * 1). store it in Drupal as a node.
 * 2). store category and tags as Drupal tags.
 * 3). store comments into Drupal.
 */
$drupalAuthors = array();
foreach (Reader::importString($data) as $s9yBlog) {
    // Just have this if statement here for type hinting purpose in Zend Studio
    if (! ($s9yBlog instanceof Zend\Feed\Reader\Entry\Rss)) {
        continue;
    }

    /**
     * Convert user ID from Serendipity to Drupal.
     *
     * If configuration "drupal.userId" is set, then use it for all imported blogs;
     * Otherwise, try to find a matched user in Durpal based on name, and use the user ID found;
     * If still nothing found, use user ID "1" (default administrator user ID in Drupal).
     */
    if (empty($config['drupal']['userId'])) {
        $author = $s9yBlog->getAuthor();
        $name = is_array($author) ? $author['name'] : $author;
        if (!array_key_exists($name, $drupalAuthors)) {
            $user = user_load_by_name($name);
            $drupalAuthors[$name] = ($user instanceof stdClass) ? $user->uid : 1;
        }

        $uid = $drupalAuthors[$name];
    } else {
        //TODO: make sure specified user ID is valid.
        $uid = $config['drupal']['userId'];
    }

    $created = $s9yBlog->getDateCreated()->getTimestamp();

    $drupalEntry = (object) array(
        'original'  => null,
        'created'   => $created,
        'changed'   => $created, // Field 'changed' will be stored correctly only after Drupal had been patched
        'timestamp' => $created, // Field 'timestamp' will be stored correctly only after Drupal had been patched
        'title'     => $s9yBlog->getTitle(),
        'uid'       => $uid,
        'type'      => $blogType,
        'language'  => LANGUAGE_NONE,
        'status'    => NODE_PUBLISHED,
        'comment'   => COMMENT_NODE_OPEN,
        'promote'   => NODE_PROMOTED,
        'sticky'    => NODE_NOT_STICKY,
        'body'      => array(
            'und' => array(
                array(
                    'summary' => ($emptySummary ? '' : $s9yBlog->getContent()),
                    'value'   => $s9yBlog->getContent(),
                    'format'  => $blogFormat,
                ),
            ),
        ),
    );

    if (array_key_exists('path', module_list())) {
        /**
         * We assume that both Serendipity and Drupal are installed under same path (e.g., http://example.com/blog), or
         * Drupal is installed at a parent level of the Serendipity installation (e.g., Serendipity was installed under
         * http://example.com/cms/s9y while Drupal will be installed under http://example.com/cms). Otherwise, URL
         * alias may not work.
         */
        $drupalEntry->path = array('alias' => preg_replace('#^https?://[^/]+/#', '', $s9yBlog->getLink()));
    }

    $tags = $s9yBlog->getCategories()->getValues();
    if (! empty($tags) && array_key_exists('taxonomy', module_list())) {
        /**
         * Store category of each blog as tag of a node in Drupal. For details, please read comments on options
         * "drupal.category.*" in file "config.ini.dist".
         *
         * Category is the first tag of a blog in the RSS 2.0 feed.
         */
        if (! empty($config['drupal']['category']['field'])) {
            $category = array_shift($tags);
            
            $drupalEntry->{$config['drupal']['category']['field']} = array(
                'und' => array(
                    array(
                        'tid' => getDrupalTermId($category, $config['drupal']['category']['vocabulary']),
                    ),
                ),
            );
        }

        /**
         * Store tags of each blog as tags of a node in Drupal. For details, please read comments on options
         * "drupal.tags.*" in file "config.ini.dist".
         */
        if (! empty($config['drupal']['tags']['field'])) {
            $termIds = array();
            foreach ($tags as $tag) {
                $termIds[] = array(
                    'tid' => getDrupalTermId($tag, $config['drupal']['tags']['vocabulary']),
                );
            }

            $drupalEntry->{$config['drupal']['tags']['field']} = array('und' => $termIds);
        }
    }

    node_save($drupalEntry); // Save a Serendipity blog as a Drupal node.

    // Try to extract blog entry ID from blog URL.
    if (preg_match($config['s9y']['patternEntryId'], $s9yBlog->getLink(), $matches)) {
        if (!is_numeric($matches[1]) || empty($matches[1])) {
            throw new \Exception(
                sprintf('Invalid Serendipity entry ID "%s" from link "%s".', $matches[1], $s9yBlog->getLink())
            );
        }
    } else {
        throw new \Exception(sprintf('Unable to extract blog entry ID from link "%s".', $s9yBlog->getLink()));
    }

    $s9yEntryId = (int)$matches[1];

    if (! empty($s9yComments[$s9yEntryId])) {
        $drupalCommentsTree = array();
        foreach ($s9yComments[$s9yEntryId] as $s9yComment) {
            $drupalComment = (object) array(
                'pid'          => (empty($s9yComment->parent_id) ? 0 : $drupalCommentsTree[$s9yComment->parent_id]),
                'nid'          => $drupalEntry->nid,
                'uid'          => 0,
                'subject'      => $s9yComment->title,
                'hostname'     => $s9yComment->ip, // It will be stored correctly only after Drupal had been patched
                'created'      => $s9yComment->timestamp,
                'status'       => ('approved' == $s9yComment->status) ? COMMENT_PUBLISHED : COMMENT_NOT_PUBLISHED,
                'name'         => $s9yComment->author,
                'mail'         => $s9yComment->email,
                'homepage'     => $s9yComment->url,
                'comment_body' => array(
                    'und' => array(
                        array(
                            'value'  => $s9yComment->body,
                            'format' => 'plain_text', // Filtered/displayed as plain text for security reason.
                        ),
                    ),
                ),
            );

            comment_save($drupalComment);
            $drupalCommentsTree[$s9yComment->id] = $drupalComment->cid;
        }
    }
}
