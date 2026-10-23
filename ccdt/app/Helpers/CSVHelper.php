<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Storage;
use App\Helpers\TableHelper;
use Illuminate\Support\Str;

/**
 * CSV Helper
 *
 * These are various functions that help with processing
 * csv or other delmimited files prior to importing.
 *
 * @author Ajay Krishna Teja Kavur
 * @author Tracy A McCormick <tam0013@mail.wvu.edu>
 * 
 */
class CSVHelper {
     /**
      * Determine delimiter used in a file
      *
      * @param string $csvFile Path to the CSV file
      *
      * @author Tracy A. McCormick <tam0013@mail.wvu.edu>
      * @return string Delimiter
      */
     public function detectDelimiter($csvFile)
     {
         $delimiters = array(
             ';' => 0,
             ',' => 0,
             "\t" => 0,
             "|" => 0
         );

         $handle = fopen($csvFile, "r");
         $firstLine = fgets($handle);
         fclose($handle);
         foreach ($delimiters as $delimiter => &$count) {
             $count = count(str_getcsv($firstLine, $delimiter));
         }

         return array_search(max($delimiters), $delimiters);
     }

     /**
     * Method to tokenize the string for multiple lines
     * @param string $line
     * @param false|string $delimiter
     */
     public function tknze($line, $delimiter) {
       // Tokenize the line
       // Define a pattern
       $pattern = '/['.$delimiter.']/';

       // preg split
       $tkns = preg_split($pattern, $line);

       // Return the array
       return $tkns;
     }

     /**
     * Method to check if the given tkns are null
     */
     public function fltrTkns($tkns) {
       // Run through the files
       foreach ($tkns as $key => $tkn) {
         // trim the token
         $tkns[ $key ] = trim($tkn);
       }

       // Return the filtered tokens
       return $tkns;
     }    

    /**
     * Creates SplFileObject or returns false if unable to 
     * create the item
     *
     * @param  string  $fltFleAbsPth    
     *  
     * @author Tracy A. McCormick <tam0013@mail.wvu.edu>  
     * @return mixed (boolean or SplFileObject) 
     */       
     public function createFltFleObj($fltFleAbsPth) {
       if (Storage::has($fltFleAbsPth) == false) {
         // If the file doesn't exists return with error
         return false;
       }

       // create flt file object
       $fltFleObj = new \SplFileObject(\storage_path()."/app/".$fltFleAbsPth);

       // Create a finfo instance
       $fleInf = new \finfo(FILEINFO_MIME_TYPE);

       // Get the file type
       $fleMime = $fleInf->file($fltFleObj->getRealPath());

       // Check the mimetype - accept text/* or application/octet-stream (for files without proper MIME type)
       if ($fleMime !== false && strpos($fleMime, 'text/') !== 0 && $fleMime !== 'application/octet-stream') {
         return false;
       }
       
       return $fltFleObj;
     }

     /**
      * Pass as $readCount to determineTypes() / checkFile() to examine every
      * line of the file instead of a sample.
      */
     const SCAN_ALL = PHP_INT_MAX;

     /**
      * True only for values that can be stored in an integer column without
      * changing them: digits only, no sign, no decimal point, and no leading
      * zero (except for "0" itself). Values such as 02134, 1.5, -3 or 1e5 are
      * numeric to PHP's is_numeric() but would be altered by an integer column.
      *
      * @param string $value
      * @return boolean
      */
     public function isPlainInteger($value) {
       return ($value !== "")
         && ctype_digit($value)
         && ($value === "0" || $value[0] !== "0");
     }

     /**
      * Returns Multidimensional Array
      * function determines if the field is numeric or string type
      * along with the max character count detected in the column
      *
      * @param boolean $header true if file has header row
      * @param string $fltFleAbsPth location of file to be checked.
      * @param integer $readCount lines to read to determine type
      *
      * @author Tracy A. McCormick <tam0013@mail.wvu.edu>
      * @return Array that contains type and character count for each detected field
      */
     public function checkFile($hasheader, $fltFleAbsPth, $readCount) {
       $fltFleObj = $this->createFltFleObj($fltFleAbsPth);

       if ($fltFleObj == false) { return false; }

       $hasheader ? $fltFleObj->seek(1) : $fltFleObj->seek(0);

       $checkArray = [];
       $count = 0;
       $delimiter = $this->detectDelimiter(\storage_path()."/app/".$fltFleAbsPth);

       // 3. Loop over the file until eof or $readCount is reached
       while ($fltFleObj->valid()) {
         // break while if we reach loop maximum read
         if ($count > $readCount) {
           break;
         }
         ++$count;

         $hdr = $fltFleObj->fgets();

         // Strip out Quotes that are sometimes seen in header rows of csv files
         $hdr = str_replace('"', "", $hdr);

         // Tokenize the line
         $tkns = $this->tknze($hdr, $delimiter);

         // Validate the tokens and filter them
         $tkns = $this->fltrTkns($tkns);

         // Blank lines (including the empty "line" SplFileObject returns after
         // the final newline) and stray single-value separator lines such as
         // ********* carry no field values. They must not influence the type
         // of the first column.
         if (trim($hdr) === "" || (count($tkns) <= 1 && count($checkArray) > 1)) {
           continue;
         }

         foreach($tkns as $x=>$x_value) {
           // array pos isn't set then we set
           // our default values
           // [0] = 1 while every value seen so far is a plain integer,
           // [1] = max length seen,
           // [2] = true once any value that is NOT a plain integer was seen
           if (!isset($checkArray[$x])) {
             $checkArray[$x] = [0, 0, false];
           }

           if (!$checkArray[$x][2] && $this->isPlainInteger($x_value)) {
             $checkArray[$x][0] = 1;
           }
           else {
             // Anything that is not a plain integer - text, blanks, decimals,
             // signed numbers, numbers with leading zeros (zip codes, ID
             // strings) or PII redaction placeholders such as <PERSON> -
             // makes the column text permanently. Previously the type was
             // decided by whichever row was read LAST, so a text column whose
             // final row was numeric was created as an integer column and the
             // text, blanks and leading zeros were silently stored as
             // 0 or truncated.
             $checkArray[$x][0] = 0;
             $checkArray[$x][2] = true;
           }

           // save character count if higher than last pass
           if ($checkArray[$x][1] < strlen($x_value)) {
             $checkArray[$x][1] = strlen($x_value);
           }
         }

       }

       // Returning detected fields
       return $checkArray;
     }

     /**
      * Determine Field Types for mysql table
      * 
      * @param boolean $hasheader set to true if header exists in the passed file
      * @param string $fltFleAbsPth full path to file that is to be checked
      * @param integer $readCount number of records to read from the file
      * @return array $fieldType array that contains type of field and size of field 
      *  used to create a mysql table prior to import of the file.
      */
     public function determineTypes($hasheader, $fltFleAbsPth, $readCount) {
         // call checkFile process the passed file
         $checkArray = $this->checkFile($hasheader, $fltFleAbsPth, $readCount);

         $fieldType = [];

         // determine final field types
         foreach($checkArray as $x=>$x_value)
         {
          // check to see if $x_value[0] is set to 0 then it should be
          // a string or if character count is over 10 we will set field
          // as a string
          if (($x_value[0] == 0) || ($x_value[1] > '10')){
            switch ($x_value[1]) {
              case 0:
              case $x_value[1] < 30:
                  array_push($fieldType, ['string', 'default', $x_value[1]]);
                  break;
              case $x_value[1] < 150:
                  array_push($fieldType, ['string', 'medium', $x_value[1]]);
                  break;
              case $x_value[1] < 500:
                  array_push($fieldType, ['string', 'big', $x_value[1]]);
                  break;
              case $x_value[1] < 2000:
                  array_push($fieldType, ['text', 'default', $x_value[1]]);
                  break;
              case $x_value[1] < 8000:
                  array_push($fieldType, ['text', 'medium', $x_value[1]]);
                  break;
              default:
                  array_push($fieldType, ['text', 'big', $x_value[1]]);
            }
          }
          elseif ($x_value[0] == 1) {
            // Every value in the column is a plain integer of at most 10
            // digits. INT holds all 9-digit numbers (max 2,147,483,647);
            // only 10-digit numbers need BIGINT. MEDIUMINT (max 8,388,607) is
            // not used: it cannot hold every 7-digit number, and with strict
            // mode off MySQL silently clamps larger values to the maximum.
            array_push($fieldType, ['integer', ($x_value[1] < 10 ? 'default' : 'big'), $x_value[1]]);
          }
       }

       return $fieldType;
     }

     /**
      * return array of generated field names
      *
      * @param integer $fieldCount Contains number of fields Requried for the Table
      *
      * @author Tracy A. McCormick <tam0013@mail.wvu.edu>
      *
      * @return Array of generated headers
      */    
     public function generateHeader($fieldCount) {
       $header = array();
       for ($pos = 0; $pos < $fieldCount; $pos++) {
         array_push($header, 'Field'.$pos);
       }
       return $header;
     }

     /**
     * Method to validate the file type and read the first line only for schema
     * Algorithm:
     * 1. Get the flatfile instance
     * 2. Validate the file type
     * 3. Tokenize the current line
     * 4. Validate the tokens and return
     * 5. Return first line as array
     * @param string $fltFlePth
     * @return mixed (array of field headers or boolean)
     */
     public function schema($fltFlePth) {
       // create flat file object
       $fltFleObj = $this->createFltFleObj($fltFlePth);

       if ($fltFleObj == false) { return false; }

       // 3. Tokenize the current line
       // Get the first line as the header
       $fltFleObj->seek(0);
       $hdr = $fltFleObj->fgets();

       // Strip out Quotes that are sometimes seen in header rows of csv files
       $hdr = str_replace('"', "", $hdr);

       // Tokenize the line
       $tkns = $this->tknze($hdr, $this->detectDelimiter(\storage_path()."/app/".$fltFlePth));
       // Validate the tokens and filter them
       $tkns = $this->fltrTkns($tkns);

       // Returning tokens
       return $tkns;
     }    
}
