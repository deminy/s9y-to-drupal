# About

**s9y-to-drupal** is a script to import [Serendipity](http://www.s9y.org) blog posts along with comments and tags into
[Drupal](http://drupal.org) 7.

I wrote this script just to convert [my personal blog](http://www.deminy.net) from Serendipity to Drupal 7, which was a
one-time task. However, pull requests are welcome.

# Requirements
* [PHP](http://www.php.net) 5.3.0+
* [Drupal](http://drupal.org) 7
* Other requirements
 * RSS 2.0 feed of the Serendipity blog site must be accessible through HTTP(S).
 * The script needs to be able to access database of the Serendipity blog site.
 * The script needs to be able to directly access working copy files of the Drupal site.

# Usage

## 1. Install Zend Framework 2

The script uses some components of [Zend Framework](http://framework.zend.com) v2.x. To install Zend Framework v2.x,
you will need to download composer.phar and run the install command under the same directory where the 'composer.json'
file is located:

```
curl -s http://getcomposer.org/installer | php && ./composer.phar install
```
## 2. Create the configuration file "*config.ini*"

You can create the file by copying directly from sample file "*config.ini.dist*", then set configuration options
following instructions in the sample file.

## 3. Set up your Drupal installation

### 3.1. Activate Drupal modules

Please make sure following core modules are activated in Drupal: *blog*, *comment*, *path* and *taxonomy*.

### 3.2. Add vocabularies and associate them with content type "blog"

We need to have vocabularies created to store categories and tags imported from Serendipity. For details, please read
comments on options "*drupal.category.\**" and "*drupal.tags.\**" in file "*config.ini.dist*".

### 3.3. Create a new text format

I'd suggest you create a new text format dedicated for imported blogs. For details, please read comments on option
"*drupal.format*" in file "*config.ini.dist*".

### 3.4. Patch your Drupal installation

You will need to run following command under your Drupal installation directory:

```
curl -s https://github.com/deminy/drupal/commit/a60e50380ade68a64174a15c49ca58b3d18d9580.patch | patch -p1
```

## 4. Run the script

Run following command and it will import Serendipity blog posts along with comments and tags into Drupal 7.

```
php s9y-to-drupal.php
```

## 5. Revert the Drupal patch

You will need to run following command under your Drupal installation directory to revert the patch previously applied:

```
curl -s https://github.com/deminy/drupal/commit/a60e50380ade68a64174a15c49ca58b3d18d9580.patch | patch -p1 -R
```

## 5. Post-import tasks

After the import is done, there are still some works to do, including:

* Check Serendipity configuration settings to set up the rest Drupal URL aliases.
* Check Serendipity *.htaccess* file to to set up other Drupal URL aliases.
* Search and fix broken links/images in the blogs.
* I'd also suggest you double check to see if timestamps of your blogs/comments are imported correctly.

Details of these post-import tasks are not covered by the script.

# Possible Issues and Known Limitations

* Draft/unpublished blogs won't be imported.
* You could experience some encoding issue, especially for people using East Asian languages. If this happens, try to
  change option "s9y.db.charset" first.
* We assume that both Serendipity and Drupal are installed under same path (e.g., *http://example.com/blog*), or Drupal
  is installed at a parent level of the Serendipity installation (e.g., Serendipity was installed under
  *http://example.com/cms/s9y* while Drupal will be installed under *http://example.com/cms*). Otherwise, URL alias may
  not work.
* The script has only been tested with Serendipity v1.5.1, Drupal v7.18 and MySQL v5.x. However, since the script uses
  Drupal built-in database driver so it should work for most major RDBMSs.

# FAQ

* Q: Why don't you load blog contents from Drupal database directly?

  A: Here are the reasons:

  Firstly, I'm not a Serendipity expert, and don't have time going through details of the Serendipity code. The RSS
  feed already contains most of the data I need in a well-organized format: blog contents, links, comments, categories
  and tags. Because of this, I don't need to have the headache to fix possible issues (e.g., timezone issues) for data
  grabbed directly from the database.

  Secondly, there are many event plugins that could be used in Serendipity for rendering blog contents, so blog data
  stored in database may not be well formed XHTML data; however, blog contents in RSS feed usually are well formed, and
  whatever we get from the RSS feed has been rendered/cleaned properly.
