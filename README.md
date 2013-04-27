FlysprayToTheBugGenieConverter
==============================

Simple PHP script to migrate Flyspray to TheBugGenie

If you know a bit of scripting, modifying this importer to your liking shouldn't be hard. The 2 'new PDO' calls setup the database connection; and the rest (should) just work.

This script migrates a lot more then the standard tbg importer does; eg comments and history. It also supports a lot more properties (basically anything I could find in flyspray).

One thing I couldn't get to migrate are the passwords. The easiest method should be to instruct your users to reset them.

If you want to login directly; you can hack .../thebuggenie/core/classes/TBGUser.class.php; around line no. 505; you'll find this block:

    elseif ($username !== null && $password !== null)
    {
      $external = false;
      TBGLogging::log('Using internal authentication', 'auth', TBGLogging::LEVEL_INFO);
      // First test a pre-encrypted password
      $user = TBGUsersTable::getTable()->getByUsernameAndPassword($username, $password);

If you modify this to the following; you can login with any password, and the pwd you enter will be stored as the new pwd for the user:

    elseif ($username !== null && $password !== null)
    {
      $external = false;
      TBGLogging::log('Using internal authentication', 'auth', TBGLogging::LEVEL_INFO);
      // First test a pre-encrypted password
      $user = TBGUsersTable::getTable()->getByUsername($username);
      $user->setPassword($password);
#      $user = TBGUsersTable::getTable()->getByUsernameAndPassword($username, $password);
