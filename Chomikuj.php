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
 *   $chomikuj = new Chomikuj('username', 'password');
 *
 *   $chomikuj->downloadFiles(
 *     // List of URLs
 *     array(
 *       'http://chomikuj.pl/chomikoryba',
 *     ),
 *
 *     // Destination folder.
 *     'downloads'
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
   * Constructor
   *
   * @param string $userName.
   *   User name.
   *
   * @param string $userPassword.
   *   User password.
   */
  public function __construct ($userName, $userPassword) {
    $this->userName         = $userName;
    $this->userPasswordHash = strtolower(md5($userPassword));
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
        'Cookie: guid=453bedfd-7258-4097-bc35-91c5fdf5f0e4',
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

    $filesInfo  = array();

    foreach ($urls as $index => &$url) {
      if (count($urls) > 1 && pathinfo($url, PATHINFO_EXTENSION) == '') {
        // Folders are downloaded individually.
        $filesInfo = array_merge($filesInfo, $this->downloadFilesInformation(array($url), $recursive));
        $url       = NULL;
      }
    }

    /**
     * Requesting information about files.
     */

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
    };

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
                '<stamp>21621</stamp>' .
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
        'Cookie: guid=453bedfd-7258-4097-bc35-91c5fdf5f0e4',
        'Content-Length: ' . strlen($data),
        'Connection: Keep-Alive',
        'Accept-Language: pl-PL,en,*',
        'User-Agent: Mozilla/5.0',
        'Host: box.chomikuj.pl',
      )
    );

    preg_match_all('/<FileEntry><id>(\d+)<\/id><agreementInfo>.*?<AgreementInfo><name>(.*?)<\/name><cost>\d+<\/cost><\/AgreementInfo>.*?<realId>(.*?)<\/realId>.*?<name>(.*?)<\/name><size>(.*?)<\/size>.*?<\/FileEntry>/', $response, $matches);

    $numMatches = count ($matches[1]);

    for ($i = 0; $i < $numMatches; ++$i) {
      $filesInfo[] = $this->fileInfoCache[$matches[1][$i]] = array(
        'id' => $matches[1][$i],
        'agreement' => $matches[2][$i],
        'realId' => $matches[3][$i],
        'name' => $matches[4][$i],
        'size' => $matches[5][$i],
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
   * @return boolean
   *   True if sucessfully downloaded all files.
   */
  public function downloadFiles ($urls, $destinationFolder, $recursive = TRUE) {

    if (empty($urls))
    // Nothing to do.
      return TRUE;

    if (!$this->login())
    // Cannot login, nothing to do.
      return FALSE;

    // Loading files information.
    $filesInfo = $this->downloadFilesInformation($urls, $recursive);

    /**
     * Requesting information about files.
     */

    $entries    = '';
    $numEntries = 0;

    foreach ($filesInfo as $index => $fileInfo) {
      $entries .=
        '<DownloadReqEntry>' .
          '<id>' . $fileInfo['id'] . '</id>' .
          '<agreementInfo>' .
            '<AgreementInfo><name>' . $fileInfo['agreement'] . '</name></AgreementInfo>' .
          '</agreementInfo>' .
        '</DownloadReqEntry>';

      ++$numEntries;
    };

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
                '<stamp>27033</stamp>' .
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
        'Cookie: guid=453bedfd-7258-4097-bc35-91c5fdf5f0e4',
        'Content-Length: ' . strlen($data),
        'Connection: Keep-Alive',
        'Accept-Language: pl-PL,en,*',
        'User-Agent: Mozilla/5.0',
        'Host: box.chomikuj.pl',
      )
    );

    preg_match_all('/<FileEntry>.*?<id>(.*?)<\/id>.*?<realId>(.*?)<\/realId>.*?<name>(.*?)<\/name><size>(.*?)<\/size>.*?<url>(.*?)<\/url>.*?<\/FileEntry>/', $response, $matches);

    $numMatches = count ($matches[1]);
    $files      = array();

    for ($i = 0; $i < $numMatches; ++$i) {
      $files[] = array(
        'name' => $name = $matches[3][$i],
        'size' => $matches[4][$i],
        'url' => htmlspecialchars_decode($matches[5][$i]),
        'destination' => $destinationFolder . '/' . $name,
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
    curl_setopt($curl, CURLOPT_HEADER, 0);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "GET");

    foreach ($files as $file) {
      curl_setopt($curl, CURLOPT_URL, $file['url']);

      if (file_exists($file['destination'])) {
        $fileSize = filesize($file['destination']);

        if ($fileSize < $file['size'])
        // Destination file already exist and is not fully downloaded, resuming download.
        	curl_setopt($curl, CURLOPT_RANGE, $fileSize . "-");
        else
        // Destination file is already fully downloaded, skipping file.
          continue;
      }
      else
        // Starting download.
        curl_setopt($curl, CURLOPT_RANGE, '0-');


      $fileHandle = fopen($file['destination'], "a");

      if (!$fileHandle)
      	return FALSE;

      curl_setopt($curl, CURLOPT_FILE, $fileHandle);

      $result = curl_exec($curl);

      fclose ($fileHandle);
    }

    curl_close($curl);

    if ($recursive) {
      foreach ($urls as $url) {

        $response = $this->request($url, array(), 'GET', '', array());

        preg_match_all('/<div id="foldersList">(.*?)<\/div>/sm', $response, $matches);

        if (@$matches[0][0]) {
          preg_match_all('/href="(.*?)"/sm', $matches[0][0], $matches);

          foreach ($matches[1] as &$match) {
            $match = 'http://chomikuj.pl' . $match;
          }

          $this->downloadFiles($matches[1], $destinationFolder);
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
  public function request ($url, $params, $method = 'POST', $data = "", $headers) {

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

    return $result;
  }
}
