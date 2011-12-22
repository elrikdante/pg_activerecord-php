<?php
  Class User extends ActiveRecord
  {
    public static $before_commit = "lower_case_email";

    function lower_case_email(){
      $this->email = strtolower($this->email);
    }
  }
?>
