# ProxyClone
Phishing framework for cloning and capturing data on targeted websites.

## Getting Started
    *Tested under Apache-2.4.7*

Required:
  1. Enable mod_rewrite: 
    * *a2enmod rewrite && service apache2 restart*
  2. Copy index.php and .htaccess to the DocumentRoot
  3. Set $target variable to starting URL


Optional:
  1. To inject code into HTML responses, set $inject = TRUE
  2. Modify $evilCode to injected JS

By default, log data will be stored in /tmp/logFile.csv.  These log instructions could easily be expanded to capture additional data.
