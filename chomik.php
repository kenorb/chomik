#!/usr/bin/env php
<?php

/**
 * @file
 * Chomikuj file downloader.
 */

/**
 * Chomikuj file downloader class.
 *
 * Example:
 * <code>
 * <?php
 *   $chomikuj = new Chomikuj(
 *
 *     // Chomikuj user name.
 *     'username',
 *
 *     // Chomikuj user password or MD5-hashed password.
 *     'password_or_hash',
 *
 *     // Boolean indicating whether specified password is a MD5 version of password(used to prevent showin password in
 *     // plain text).
 *     FALSE,
 *
 *   );
 *
 *   $chomikuj->downloadFiles(
 *
 *     // Required. List of URLs.
 *     array(
 *       'http://chomikuj.pl/chomikoryba',
 *     ),
 *
 *     // Optional. File extensions to include. Defaults to all extensions (array()).
 *     array('pdf', 'docx'),
 *
 *     // Optional. Destination folder. May be empty. Defaults to current directory.
 *     'downloads',
 *
 *     // Optional. Recurses into subdirectories. Defaults to TRUE.
 *     TRUE,
 *
 *     // Optional. Creates folder structure. Defaults to TRUE.
 *     TRUE,
 *
 *     // Optional. Will not overwrite existing files. Defaults to FALSE.
 *     FALSE,
 *
 *   );
 * ?>
 * </code>
 */
class Chomikuj
{
  /**
   * @var string $userName
   *   Name of the user.
   */
  protected $userName;

  /**
   * @var string $userPasswordHash
   *   Hashed user password.
   */
  protected $userPasswordHash;

  /**
   * @var string $authToken
   *   Authentication token retrieved from chomikbox service.
   */
  protected $authToken;

  /**
   * @var array $log
   *   Debugging log lines.
   */
  protected $log = array();

  /**
   * @var integer $lastLoginStamp
   *   Last login timestamp.
   */
  protected $lastLoginStamp = 0;

  /**
   * @var array $fileInfoCache
   *   Associative array of file ID/file ULR to file information array.
   */
  protected $fileInfoCache = array();

  /**
   * @var number $stamp
   *   Message sequence stamp. We need to increase it for every request.
   */
  protected $stamp = 0;

  /**
   * Constructor
   *
   * @param string $userName.
   *   User name.
   *
   * @param string $userPassword.
   *   User password.
   *
   * @param boolean $passwordHashed
   *   Indicates whether given password is already md5-ed.
   */
  public function __construct ($userName, $userPassword, $passwordHashed = FALSE) {
    $this->userName         = $userName;
    $this->userPasswordHash = $passwordHashed ? $userPassword : strtolower(md5($userPassword));
  }

  /**
   * Logins or relogins user. Called automatically before download.
   *
   * @return boolean
   *   True if login passed.
   */
  public function login () {

    if ($this->lastLoginStamp !== 0 && time() < ($this->lastLoginStamp + 300))
    // Already logged in.
      return TRUE;

    if (php_sapi_name() === 'cli')
      $log = "Logging in to chomikbox service as \"$this->userName\"... ";

    $response = $this->request(

      // URL
      'http://box.chomikuj.pl/services/ChomikBoxService.svc',

      // GET params.
      array(),

      // Method
      'POST',

      // Request data
      $data =
        '<?xml version="1.0" encoding="UTF-8"?>' .
        '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">' .
          '<s:Body>' .
            '<Auth xmlns="http://chomikuj.pl/">' .
              '<name>' . $this->userName . '</name>' .
              '<passHash>' . $this->userPasswordHash . '</passHash>' .
              '<ver>4</ver>' .
              '<client>' .
                '<name>chomikbox</name>' .
                '<version>2.0.7.9</version>' .
              '</client>' .
            '</Auth>' .
          '</s:Body>' .
        '</s:Envelope>',

      // Headers.
      array(
        'POST /services/ChomikBoxService.svc HTTP/1.1',
        'SOAPAction: http://chomikuj.pl/IChomikBoxService/Auth',
        'Content-Type: text/xml;charset=utf-8',
        'Content-Length: ' . strlen($data),
        'Connection: Keep-Alive',
        'Accept-Language: pl-PL,en,*',
        'User-Agent: Mozilla/5.0',
        'Host: box.chomikuj.pl',
      )
    );

    preg_match('/\<a:token\>(.*?)\<\/a:token\>/', $response, $matches);

    $this->authToken      = @$matches[1];
    $this->lastLoginStamp = time ();

    if (php_sapi_name() === 'cli') {
      preg_match('/<a:status>(.*?)<\/a:status>/sm', $response, $matches);
      $status = strtoupper($matches[1]);

      $log .= $status . ".\n";

      if (php_sapi_name() === 'cli') {
        if ($status === 'OK')
          echo $log;
        else
          fwrite(STDERR, $log);
      }
    }

    return !empty($this->authToken);
  }

  /**
   * Retrieves file information about given URLs.
   *
   * @param string $urls
   *   Urls to the files.
   *
   * @return array
   *   List of file information.
   */
  public function downloadFilesInformation ($urls, $recursive = TRUE) {

    if (!$this->login())
    // Cannot login, nothing to do.
      return FALSE;

    if (php_sapi_name() === 'cli') {
      echo "  Downloading files information for specified URLs:\n";

      foreach ($urls as $url) {
        echo "    - $url\n";
      }
    }

    $filesInfo  = array();

    foreach ($urls as $index => &$url) {
      if (count($urls) > 1 && pathinfo($url, PATHINFO_EXTENSION) == '') {

        if (php_sapi_name() === 'cli') {
          echo "      It's a folder, merging its content\n";
        }

        // Folders are downloaded individually.
        $filesInfo = array_merge($filesInfo, $this->downloadFilesInformation(array($url), $recursive));
        $url       = NULL;
      }
    }

    /**
     * Requesting information about files.
     */

    if (php_sapi_name() === 'cli') {
      echo "  Preparing request to get information about files...";
    }

    $entries    = '';
    $numEntries = 0;

    foreach ($urls as $url) {
      if ($url === NULL)
      // Entry skipped.
        continue;

      $entries .=
        '<DownloadReqEntry>' .
          '<id>/' . substr($url, strlen('http://chomikuj.pl/'))  . '</id>' .
        '</DownloadReqEntry>';

      ++$numEntries;
    }

    if (php_sapi_name() === 'cli') {
      echo " OK.\n";
    }

    $response = $this->request(

      // URL
      'http://box.chomikuj.pl/services/ChomikBoxService.svc',

      // GET params.
      array(),

      // Method
      'POST',

      // Request data
      $data =
        '<?xml version="1.0" encoding="UTF-8"?>' .
        '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">' .
          '<s:Body>' .
            '<Download xmlns="http://chomikuj.pl/">' .
              '<token>' . $this->authToken . '</token>' .
              '<sequence>' .
                '<stamp>' . ($this->stamp++) . '</stamp>' .
                '<part>0</part>' .
                '<count>1</count>' .
              '</sequence>' .
              '<disposition>download</disposition>' .
              '<list>' .
                $entries .
              '</list>' .
            '</Download>' .
          '</s:Body>' .
        '</s:Envelope>',

      // Headers.
      array(
        'POST /services/ChomikBoxService.svc HTTP/1.1',
        'SOAPAction: http://chomikuj.pl/IChomikBoxService/Download',
        'Content-Type: text/xml;charset=utf-8',
        'Content-Length: ' . strlen($data),
        'Connection: Keep-Alive',
        'Accept-Language: pl-PL,en,*',
        'User-Agent: Mozilla/5.0',
        'Host: box.chomikuj.pl',
      )
    );

    preg_match_all('/<FileEntry><id>(\d+)<\/id><agreementInfo>.*?<AgreementInfo><name>(.*?)<\/name><cost>(\d+)<\/cost><\/AgreementInfo>.*?<realId>(.*?)<\/realId>.*?<name>(.*?)<\/name><size>(.*?)<\/size>.*?<\/FileEntry>/', $response, $matches);

    $numMatches = count ($matches[1]);

    if (php_sapi_name() === 'cli') {
      echo "  Received $numMatches records with information about files.\n";
    }

    for ($i = 0; $i < $numMatches; ++$i) {
      $filesInfo[] = $this->fileInfoCache[$matches[1][$i]] = array(
        'id' => $matches[1][$i],
        'agreement' => $matches[2][$i],
        'cost' => $matches[3][$i],
        'realId' => $matches[4][$i],
        'name' => $matches[5][$i],
        'size' => $matches[6][$i],
      );
    }

    return $filesInfo;
  }

  /**
   * Downloads specified file into the destination folder.
   *
   * @param string $urls
   *   List of file URLs.
   *
   * @param array $extensions
   *   List of file extensions to download. Leave empty to download all files.
   *
   * @param boolean $recursive
   *   True to download also subfolders.
   *
   * @param boolean $structure
   *   True to create full folder structure.
   *
   * @param boolean $overwrite
   *   True to overwrite existing files.
   *
   * @return boolean
   *   True if sucessfully downloaded all files.
   */
  public function downloadFiles ($urls, $extensions = array(), $destinationFolder = '', $recursive = TRUE, $structure = TRUE, $overwrite = FALSE) {

    if (empty($urls))
    // Nothing to do.
    {
      if (php_sapi_name() === 'cli') {
        echo "  No URLs given to download.\n";
      }

      return TRUE;
    }

    if (!$this->login())
    // Cannot login, nothing to do.
      return FALSE;

    // Loading files information.
    $filesInfo = $this->downloadFilesInformation($urls, $recursive);

    $iterationSize = 1;

    // Maximum of $iterationSize files per request.
    for ($iteration = 0; $iteration < count($filesInfo) / $iterationSize; ++$iteration) {

      if (php_sapi_name() === 'cli') {
        echo "  Download iteration " . ($iteration + 1) . " / " . (int) count($filesInfo) / $iterationSize . "\n";
      }

      /**
       * Requesting information about files.
       */

      $entries    = '';
      $numEntries = 0;

      foreach (array_slice($filesInfo, $iteration * $iterationSize, $iterationSize) as $index => $fileInfo) {
        $entries .=
          '<DownloadReqEntry>' .
            '<id>' . $fileInfo['id'] . '</id>' .
            '<agreementInfo>' .
              '<AgreementInfo><name>' . $fileInfo['agreement'] . '</name>';

        if ($fileInfo['agreement'] !== 'small') {
          $entries .= '<cost>' . $fileInfo['cost'] . '</cost>';
        }

        $entries .=
              '</AgreementInfo>' .
            '</agreementInfo>' .
          '</DownloadReqEntry>';

        ++$numEntries;

        if ($numEntries > $iterationSize)
          break;
      }

      $response = $this->request(

        // URL
        'http://box.chomikuj.pl/services/ChomikBoxService.svc',

        // GET params.
        array(),

        // Method
        'POST',

        // Request data
        $data =
          '<?xml version="1.0" encoding="UTF-8"?>' .
          '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">' .
            '<s:Body>' .
              '<Download xmlns="http://chomikuj.pl/">' .
                '<token>' . $this->authToken . '</token>' .
                '<sequence>' .
                  '<stamp>' . ($this->stamp++) . '</stamp>' .
                  '<part>0</part>' .
                  '<count>1</count>' .
                '</sequence>' .
                '<disposition>download</disposition>' .
                '<list>' .
                  $entries .
                '</list>' .
              '</Download>' .
            '</s:Body>' .
          '</s:Envelope>',

        // Headers.
        array(
          'POST /services/ChomikBoxService.svc HTTP/1.1',
          'SOAPAction: http://chomikuj.pl/IChomikBoxService/Download',
          'Content-Type: text/xml;charset=utf-8',
          'Content-Length: ' . strlen($data),
          'Connection: Keep-Alive',
          'Accept-Language: pl-PL,en,*',
          'User-Agent: Mozilla/5.0',
          'Host: box.chomikuj.pl',
        )
      );

      preg_match_all('/<globalId>(.*?)<\/globalId>/', $response, $matches);

      $path = @substr($matches[1][0], 1);

      // Removing non-ascii characters.
      $path = preg_replace('/[^(\x20-\x7F)]*/', '', $path);

      preg_match_all('/<FileEntry>.*?<id>(.*?)<\/id>.*?<realId>(.*?)<\/realId>.*?<name>(.*?)<\/name><size>(.*?)<\/size>.*?(<url i:nil="true"\/>|<url>(.*?)<\/url>).*?<\/FileEntry>/', $response, $matches);


      $numMatches = count ($matches[1]);
      $files      = array();

      for ($i = 0; $i < $numMatches; ++$i) {
        $name       = $matches[3][$i];

        $ext        = pathinfo($name, PATHINFO_EXTENSION);

        if (!empty($extensions) && !in_array($ext, $extensions)) {
          // Skipping file.
          continue;
        }

        $files[] = array(
          'name' => $name,
          'size' => $matches[4][$i],
          'url' => htmlspecialchars_decode($matches[6][$i]),
          'destination' => $destinationFolder . '/' . ($structure ? ($path . '/') : '') . $name,
          'path' => $path,
        );
      }

      /**
       * Downloading files.
       */

      $curl = curl_init();

      curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Icy-MetaData: 1',
        'Connection: Keep-Alive',
        'Accept-Language: pl-PL,en,*',
        'User-Agent: Mozilla/5.0',
      ));

      curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
      curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "GET");


      foreach ($files as $file) {

        if (php_sapi_name() === 'cli') {

          $url_limited = strlen($file['url']) > 80 ? (substr($file['url'], 0, 20) . '...' . substr($file['url'], -60)) : $file['url'];

          echo "Downloading URL \"{$url_limited}\" ({$file['size']} bytes). ";
        }

        curl_setopt($curl, CURLOPT_URL, $file['url']);

        if ($structure && !file_exists(dirname($file['destination']))) {
          @mkdir(dirname($file['destination']), 0777, true);
        }

        if (file_exists($file['destination'])) {

          if ($structure && !$overwrite) {
            // Creating new file has no sense.

            if (php_sapi_name() === 'cli') {
              echo "Already downloaded, skipping.\n";
            }

            continue;
          }

          if ($overwrite) {
            // Removing destination file.

            if (php_sapi_name() === 'cli')
              echo "Will Overwrite. ";

            unlink($file['destination']);
          }
          else {
            // Generating better name.
            $path = $file['destination'];

            do {
              $file_name = pathinfo($path, PATHINFO_FILENAME);

              preg_match('/\((\d+)\)$/', $file_name, $matches);

              if (@is_numeric($matches[1])) {
                // Incrementing value in "(...)".
                $number = $matches[1] + 1;

                // Removing "(...)" from the end of file name.
                $file_name = substr($file_name, 0, strlen($file_name) - strlen($number) - 2);
              }
              else {
                // Starting from "...(2)".
                $number = 2;
              }

              $ext  = pathinfo($path, PATHINFO_EXTENSION);

              // Constructing file path as "filename(NUMBER).ext".
              $path =
                pathinfo($path, PATHINFO_DIRNAME) . '/' .
                $file_name . '(' . $number . ')' .
                ($ext ? ('.' . $ext) : '');

              // If file path already exist, we will need to generate another path.
            } while (file_exists($path));

            $file['destination'] = $path;
          }
        }

        if (file_exists($file['destination'] . '.part')) {
          // File is not complete.

          $fileSize = filesize($file['destination'] . '.part');

          if ($fileSize < $file['size']) {
            // Destination file already exist and is not fully downloaded, resuming download.
            curl_setopt($curl, CURLOPT_RANGE, $fileSize . "-");

            if (php_sapi_name() === 'cli')
              echo "Resuming part $fileSize - {$file['size']}... ";

            $fileHandle = fopen($file['destination'] . '.part', "a");
          }
          else {
            // Destination file is already fully downloaded, renaming and going to the next file.

            if (php_sapi_name() === 'cli')
              echo "Already downloaded, skipping.\n";

            // Removing ".part" from file name.
            rename($file['destination'] . '.part', $file['destination']);

            continue;
          }
        }
        else {
          // Starting download.
          curl_setopt($curl, CURLOPT_RANGE, '0-');

          @$fileHandle = fopen($file['destination'] . '.part', "a");

          if (php_sapi_name() === 'cli')
            echo "Starting... ";
        }

        if (!$fileHandle) {
          // Could not open handle

          echo "ERROR.\n";
          fwrite(STDERR, "Could not create file \"{$file['destination']}\" for chomikbox file download, exiting.");

          return FALSE;
        }

        curl_setopt($curl, CURLOPT_BINARYTRANSFER, 1);
        curl_setopt($curl, CURLOPT_FILE, $fileHandle);

        $result    = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        fclose ($fileHandle);

        if ($http_code == 404) {
          // We don't need zero-sized file.
          unlink($file['destination']);
        }
        else {
          // Removing ".part" from file name.
          rename($file['destination'] . '.part', $file['destination']);
        }

        if (php_sapi_name() === 'cli')
          echo "Done.\n";
      }

      curl_close($curl);
    }

    if ($recursive) {

      if (php_sapi_name() === 'cli') {
        echo "  Recursing into given URLs...\n";
      }

      // We will try to download subfolders.
      foreach ($urls as $url) {
        $response = $this->request($url, array(), 'GET', '', array());

        // Searching for folder names.
        preg_match_all('/<div id="foldersList">(.*?)<\/div>/sm', $response, $matches);

        if (@$matches[0][0]) {
          // We have some folders.
          preg_match_all('/href="(.*?)"/sm', $matches[0][0], $matches);

          foreach ($matches[1] as &$match) {
            $match = 'http://chomikuj.pl' . $match;
          }

          // Downloading subfolders' files.
          $this->downloadFiles($matches[1], $extensions, $destinationFolder, $recursive, $structure, $overwrite);
        }
      }
    }

    return $filesInfo;
  }

  /**
   * Creates and invokes a GET/POST request.
   *
   * @param string $url
   *   URL.
   *
   * @param array $params
   *   GET params array.
   *
   * @param string $method
   *   Request method, e.g. "GET", "POST", "DELETE".
   *
   * @param string $data
   *   Data string.
   *
   * @param array $headers
   *   Custom HTTP headers.
   */
  public function request ($url, $params, $method = 'POST', $data = "", $headers = array()) {

    $curl = curl_init($url);

    if ($method == 'POST')
      curl_setopt($curl, CURLOPT_POST, 1);
    else
      curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);

    curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_0);
    curl_setopt($curl, CURLOPT_HEADER, false);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 0);

    if (!empty($data))
      curl_setopt($curl, CURLOPT_POSTFIELDS, $data);

    $result = curl_exec($curl);

    curl_close($curl);

    preg_match('/<a:messageSequence><stamp>(\d+)<\/stamp>/', $result, $matches);

    if (!empty($matches[1])) {
      $this->stamp = $matches[1] + 1000;
    }

    return $result;
  }
}

/**
 * CLI Support.
 */

if (php_sapi_name() === 'cli') {

  /**
   * parseArgs Command Line Interface (CLI) utility function.
   * @author Patrick Fisher <patrick@pwfisher.com>
   * @see http://github.com/pwfisher/CommandLine.php
   */
  function parseArgs($argv) {
    $argv = $argv ? $argv : $_SERVER['argv']; array_shift($argv); $o = array();
    foreach ($argv as $a) {
      if (substr($a, 0, 2) == '--') { $eq = strpos($a, '=');
        if ($eq !== false) { $o[substr($a, 2, $eq - 2)] = substr($a, $eq + 1); }
        else { $k = substr($a, 2); if (!isset($o[$k])) { $o[$k] = true; } } }
      else if (substr($a, 0, 1) == '-') {
        if (substr($a, 2, 1) == '=') { $o[substr($a, 1, 1)] = substr($a, 3); }
        else { foreach (str_split(substr($a, 1)) as $k) { if (!isset($o[$k])) { $o[$k] = true; } } } }
      else { $o[] = $a; } }
    return $o;
  }

  $args = parseArgs($argv);

  if (empty($args['recursive']))
    $args['recursive'] = isset($args['r']) ? $args['r'] : FALSE;

  if (empty($args['structure']))
    $args['structure'] = isset($args['s']) ? $args['s'] : FALSE;

  if (empty($args['overwrite']))
    $args['overwrite'] = isset($args['o']) ? $args['o'] : FALSE;

  if (empty($args['help']))
    $args['help']      = isset($args['h']) ? $args['h'] : FALSE;

  if (empty($args['help']))
    $args['help']      = in_array('/?', $args, TRUE);

  if (empty($args) || !empty($args['help']) || empty($args['user']) || (empty($args['password']) && empty($args['hash'])) || empty($args['url'])) {
    echo
      "\nChomikuj Downloader\nVersion 0.1\n\nUsage:\n" .
      "  php " . $argv[0] . " --user=USER --password=PASSWORD --url=\"http://chomikuj.pl/PATH\" [optional options] destination\n" .
      "  php " . $argv[0] . " --user=USER --hash=MD5_PASSWORD --url=\"http://chomikuj.pl/PATH\" [optional options] destination\n\n" .
      "Required Options:\n" .
      "  --user=USER          Uses specified user name for authentication.\n" .
      "  --password=PASSWORD  Uses specified user password for authentication.\n" .
      "  --hash=HASH          Uses specified user password hash for authentication.\n" .
      "  --url=URL            Downloads files from the specified URL.\n\n" .
      "Optional Options:\n" .
      "  --ext=EXTENSIONS     Downloads only files of the specified extensions, separated by comma.\n" .
      "  -r, --recursive      Downloads also all subdirectories.\n" .
      "  -s, --structure      Creates full folder structure.\n" .
      "  -o, --overwrite      Overwrites existing files.\n" .
      "  -h, --help, /?       Shows this help.\n\n" .
      "NOTE:\n" .
      "  - To log in you may use password OR hash.\n" .
      "  - URLs must start with \"http://chomikuj.pl/\" and must NOT end with a slash.\n" .
      "  - By default the script downloads files into the folder from where it was run.\n\n" .
      "Examples:\n" .
      "  php " . $argv[0] . " --user=chomikoryba --password=ryba123 --url=\"http://chomikuj.pl/chomikoryba\" -r -s downloads\n" .
      "  php " . $argv[0] . " --user=chomikoryba --hash=233b5774ada09b36458f69a04c9718e9 --url=\"http://chomikuj.pl/chomikoryba/Myriad+Pro+%28CE%29-TTF\" -r --ext=\"ttf,otf\" fonts\n" .
      "\n";

    exit;
  }

  if (empty($args[0]))
  // No destination folder.
    $args[0] = './';

  $chomikuj = new Chomikuj(
    // Chomikuj user name.
    $args['user'],

    // Chomikuj user password or MD5-hashed password.
    !empty($args['hash']) ? $args['hash'] : $args['password'],

    // Boolean indicating whether specified password is a MD5 version of password(used to prevent showin password in
    // plain text).
    !empty($args['hash'])
  );

  $chomikuj->downloadFiles(
    // List of URLs. Single URL accepted.
    array($args['url']),

    // File extensions to include.
    isset($args['ext']) ? (explode(',', $args['ext'])) : array(),

    // Destination folder.
    $args[0],

    // Recurse into subdirectories.
    !empty($args['recursive']),

    // Create folder structure.
    !empty($args['structure']),

    // Overwrite existing files.
    !empty($args['overwrite'])
  );
}
