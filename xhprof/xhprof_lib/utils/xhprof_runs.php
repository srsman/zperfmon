<?php

/*
 *   M O D I F I E D   as   follows
 *
 * Copyright (c) 2010 Zynga
 *
 * Added utility functions, YUI support and igbinary+bzip support.
 */

//
//  Copyright (c) 2009 Facebook
//
//  Licensed under the Apache License, Version 2.0 (the "License");
//  you may not use this file except in compliance with the License.
//  You may obtain a copy of the License at
//
//      http://www.apache.org/licenses/LICENSE-2.0
//
//  Unless required by applicable law or agreed to in writing, software
//  distributed under the License is distributed on an "AS IS" BASIS,
//  WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
//  See the License for the specific language governing permissions and
//  limitations under the License.
//

//
// This file defines the interface iXHProfRuns and also provides a default
// implementation of the interface (class XHProfRuns).
//

/**
 * iXHProfRuns interface for getting/saving a XHProf run.
 *
 * Clients can either use the default implementation,
 * namely XHProfRuns_Default, of this interface or define
 * their own implementation.
 *
 * @author Kannan
 */
interface iXHProfRuns {

  /**
   * Returns XHProf data given a run id ($run) of a given
   * type ($type).
   *
   * Also, a brief description of the run is returned via the
   * $run_desc out parameter.
   */
  public function get_run($run_id, $type, &$run_desc);

  /**
   * Save XHProf data for a profiler run of specified type
   * ($type).
   *
   * The caller may optionally pass in run_id (which they
   * promise to be unique). If a run_id is not passed in,
   * the implementation of this method must generated a
   * unique run id for this saved XHProf run.
   *
   * Returns the run id for the saved XHProf run.
   *
   */
  public function save_run($xhprof_data, $type, $run_id = null);
}


/**
 * XHProfRuns_Default is the default implementation of the
 * iXHProfRuns interface for saving/fetching XHProf runs.
 *
 * It stores/retrieves runs to/from a filesystem directory
 * specified by the "xhprof.output_dir" ini parameter.
 *
 * @author Kannan
 */
class XHProfRuns_Default implements iXHProfRuns {

  private $dir = '';

  private function gen_run_id($type) {
    return uniqid();
  }

  private function file_name($run_id, $type) {

    $file = "$run_id.$type";

    if (!empty($this->dir)) {
      $file = $this->dir . "/" . $file;
    }

    return $file;
  }

  public function __construct($dir = null) {

    // if user hasn't passed a directory location,
    // we use the xhprof.output_dir ini setting
    // if specified, else we default to the directory
    // in which the error_log file resides.

    if (empty($dir)) {
      $dir = ini_get("xhprof.output_dir");
      if (empty($dir)) {

        // some default that at least works on unix...
        $dir = "/tmp";

        xhprof_error("Warning: Must specify directory location for XHProf runs. ".
                     "Trying {$dir} as default. You can either pass the " .
                     "directory location as an argument to the constructor ".
                     "for XHProfRuns_Default() or set xhprof.output_dir ".
                     "ini param.");
      }
    }
    $this->dir = $dir;
  }

  /* function to read file ,
   * Read bz compressed file ,
   * if the file is not compressed in bz format ,
   * file is read normally using file_get_contents
   */
  function read_file($file_name) {
	  $content = file_get_contents($file_name);
	  $unbzipped = bzdecompress($content);

	  if (is_int($unbzipped)) {
		  return $content;
	  } else {
		  return $unbzipped;
	  }
  }

  function load_profile($file_name) {
	  $data = XHProfRuns_Default::read_file($file_name);
	  $header = unpack("N/", $data);
	  $igbinary_version = $header[1];
	  
	  if ($igbinary_version >= 0 && $igbinary_version <= 3) {
		  return igbinary_unserialize($data);
	  } else {
		  return unserialize($data);
	  }
  }
  
  public function get_run($run_id, $type, &$run_desc) {
    $file_name = $this->file_name($run_id, $type);

    if (!file_exists($file_name)) {
      $file_name = substr($this->file_name($run_id,""), 0 , -1);
    }
	
    if (!file_exists($file_name)) {
      xhprof_error("Could not find file $file_name");
      $run_desc = "Invalid Run Id = $run_id";
      return null;
    }

    $contents = $this->load_profile($file_name);
    $run_desc = "XHProf Run (Namespace=$type)";
    return $contents;
  }


  //
  // Open profile file with given run_id and type at the dir location
  // run_path.
  //
  public function get_run_fqpn($run_id, $type, &$run_desc, $run_path) 
  {
    $file_name = "{$run_path}/{$run_id}.{$type}";
    
    if (!file_exists($file_name)) {
      xhprof_error("get_run_fqpn: Could not find file $file_name");
      $run_desc = "Invalid Run Id = $run_id";
      return null;
    }

    $contents = $this->load_profile($file_name);
    $run_desc = "XHProf Run (Namespace=$type, dir={$run_path})";
    return $contents;
  }

  //
  // Open profile file with given full file name
  //
  public function get_run_file($file_name, &$run_desc) 
  {
    if (!file_exists($file_name)) {
      xhprof_error("get_run_file: Could not find file $file_name");
      $run_desc = "Invalid profile name";
      return null;
    }    

    $contents = $this->load_profile($file_name);
    $run_desc = "XHProf Run (file name={$file_name})";
    return $contents;
  }
  
  public function save_run($xhprof_data, $type, $run_id = null) {

    // Use PHP serialize function to store the XHProf's
    // raw profiler data.
    if (function_exists(igbinary_serialize)) {
          $xhprof_data = igbinary_serialize($xhprof_data);
    } else {
          $xhprof_data = serialize($xhprof_data);
    }

    if ($run_id === null) {
      $run_id = $this->gen_run_id($type);
    }

    $file_name = $this->file_name($run_id, $type);
    $file = fopen($file_name, 'w');

    if ($file) {
      fwrite($file, $xhprof_data);
      fclose($file);
    } else {
      xhprof_error("Could not open $file_name\n");
    }

    // echo "Saved run in {$file_name}.\nRun id = {$run_id}.\n";
    return $run_id;
  }

  #
  # Save xhprof data to the absolute path $run_path
  #
  public function save_run_fqpn($xhprof_data, $type, $run_id = null, $run_path) 
  {
    // Use PHP serialize function to store the XHProf's
    // raw profiler data.
    if (function_exists(igbinary_serialize)) {
          $xhprof_data = igbinary_serialize($xhprof_data);
    } else {
          $xhprof_data = serialize($xhprof_data);
    }    

    if ($run_id === null) {
      $run_id = $this->gen_run_id($type);
    }

    $file_name = "{$run_path}/{$run_id}.{$type}";
    $file = fopen($file_name, 'w');

    if ($file) {
      fwrite($file, $xhprof_data);
      fclose($file);
    } else {
      xhprof_error("Could not open $file_name\n");
    }

    // echo "Saved run in {$file_name}.\nRun id = {$run_id}.\n";
    return $run_id;
  }
}
