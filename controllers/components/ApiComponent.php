<?php
/*=========================================================================
MIDAS Server
Copyright (c) Kitware SAS. 20 rue de la Villette. All rights reserved.
69328 Lyon, FRANCE.

See Copyright.txt for details.
This software is distributed WITHOUT ANY WARRANTY; without even
the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR
PURPOSE.  See the above copyright notices for more information.
=========================================================================*/

/** Component for api methods */
class Slicerpackages_ApiComponent extends AppComponent
{

  /**
   * Helper function for verifying keys in an input array
   */
  private function _checkKeys($keys, $values)
    {
    foreach($keys as $key)
      {
      if(!array_key_exists($key, $values))
        {
        throw new Exception('Parameter '.$key.' must be set.', -1);
        }
      }
    }

  /**
   * Helper function to get the user from token or session authentication
   */
  private function _getUser($args)
    {
    $componentLoader = new MIDAS_ComponentLoader();
    $authComponent = $componentLoader->loadComponent('Authentication', 'api');
    return $authComponent->getUser($args, null);
    }

  /**
   * Get the name of the requested dashboard
   * @param os The target operating system of the package
   * @param arch The os chip architecture (i386, amd64, etc)
   * @param name The name of the package (ie installer name)
   * @param revision The svn or git revision of the installer
   * @param submissiontype Whether this is from a nightly, experimental, continuous, etc dashboard
   * @param packagetype Installer, data, extension, etc
   * @return Status of the upload
   */
  public function uploadPackage($args)
    {
    set_time_limit(0);
    $this->_checkKeys(array('os',
                            'arch',
                            'name',
                            'revision',
                            'submissiontype',
                            'packagetype'), $args);

    $userDao = $this->_getUser($args);
    if($userDao === false)
      {
      throw new Exception('Invalid user authentication', -1);
      }

    $inputfile = 'php://input';
    $tmpfile = tempnam(BASE_PATH.'/tmp/misc', 'slicerpackage');
    $in = fopen($inputfile, 'rb');
    $out = fopen($tmpfile, 'wb');

    $bufSize = 1024 * 1024;

    $size = 0;
    // read from input and write into file
    while(connection_status() == CONNECTION_NORMAL && ($buf = fread($in, $bufSize)))
      {
      $size += strlen($buf);
      fwrite($out, $buf);
      }
    fclose($in);
    fclose($out);

    $modelLoader = new MIDAS_ModelLoader();
    $communityModel = $modelLoader->loadModel('Community');
    $community = $communityModel->getByName('Slicer');

    if(!$community)
      {
      unlink($tmpfile);
      throw new Exception('The Slicer community does not exist', -1);
      }
    $folderModel = $modelLoader->loadModel('Folder');
    $name = ucfirst($args['submissiontype']);
    $publicFolder = $folderModel->load($community->getPublicfolderId());
    $typeFolder = $folderModel->getFolderExists($name, $publicFolder);

    if(!$typeFolder)
      {
      unlink($tmpfile);
      throw new Exception('Folder '.$name.' does not exist in the Slicer community', -1);
      }
    $componentLoader = new MIDAS_ComponentLoader();
    $uploadComponent = $componentLoader->loadComponent('Upload');
    $item = $uploadComponent->createUploadedItem($userDao, $args['name'], $tmpfile, $typeFolder);

    unlink($tmpfile);

    if(!$item)
      {
      throw new Exception('Failed to create item', -1);
      }
    $packageModel = $modelLoader->loadModel('Package', 'slicerpackages');
    $packageModel->loadDaoClass('PackageDao', 'slicerpackages');
    $packageDao = new Slicerpackages_PackageDao();
    $packageDao->setItemId($item->getKey());
    $packageDao->setSubmissiontype($args['submissiontype']);
    $packageDao->setPackagetype($args['packagetype']);
    $packageDao->setOs($args['os']);
    $packageDao->setArch($args['arch']);
    $packageDao->setRevision($args['revision']);
    $packageModel->save($packageDao);

    return array('package' => $packageDao);
    }

  /**
   * Get all available slicer packages
   * @param os (Optional) The target operating system of the package (linux | win | macosx)
   * @param arch (Optional) The os chip architecture (i386 | amd64)
   * @param submissiontype (Optional) Dashboard model used to submit (nightly | experimental | continuous)
   * @param packagetype (Optional) The package type (installer | data | extension)
   * @param revision (Optional) The revision of the package
   * @param order (Optional) What parameter to order results by (revision | packagetype | submissiontype | arch | os)
   * @param direction (Optional) What direction to order results by (asc | desc).  Default asc
   * @param limit (Optional) Limit result count. Must be a positive integer.
   * @param release (Optional) Only search in the packages that have been marked as Release packages.
   * @return An array of slicer packages
   */
  public function getPackages($args)
    {
    $modelLoad = new MIDAS_ModelLoader();
    $packagesModel = $modelLoad->loadModel('Package', 'slicerpackages');
    $packagesModel->loadDaoClass('PackageDao', 'slicerpackages');
    $itemModel = $modelLoad->loadModel('Item');

    if(array_key_exists('release', $args))
      {
      $communityModel = $modelLoad->loadModel('Community');
      $community = $communityModel->getByName('Slicer');
      $folders = $community->getPublicFolder()->getFolders();
      foreach($folders as $folder)
        {
        if($folder->getName() == 'Release')
          {
          $daos = array();
          foreach($folder->getFolders() as $subFolder)
            {
            foreach($subFolder->getItems() as $item)
              {
              $package = $packagesModel->getByItemId($item->getKey());
              if($package)
                {
                $daos[] = $package;
                }
              }
            }
          break;
          }
        }
      }
    else
      {
      $daos = $packagesModel->get($args);
      }

    $results = array();
    foreach($daos as $dao)
      {
      $revision = $itemModel->getLastRevision($dao->getItem());
      $bitstreams = $revision->getBitstreams();
      $bitstreamsArray = array();
      foreach($bitstreams as $bitstream)
        {
        $bitstreamsArray[] = array('bitstream_id' => $bitstream->getKey(),
                                   'name' => $bitstream->getName(),
                                   'md5' => $bitstream->getChecksum(),
                                   'size' => $bitstream->getSizebytes());
        }
      $results[] = array('package_id' => $dao->getKey(),
                         'item_id' => $dao->getItemId(),
                         'os' => $dao->getOs(),
                         'arch' => $dao->getArch(),
                         'revision' => $dao->getRevision(),
                         'submissiontype' => $dao->getSubmissiontype(),
                         'package' => $dao->getPackagetype(),
                         'name' => $dao->getItem()->getName(),
                         'date_creation' => $dao->getItem()->getDateCreation(),
                         'bitstreams' => $bitstreamsArray);
      }
    return $results;
    }

} // end class
