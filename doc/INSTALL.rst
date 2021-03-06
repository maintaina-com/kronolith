=========================
 Installing Kronolith H5
=========================

:Contact: kronolith@lists.horde.org

.. contents:: Contents
.. section-numbering::

This document contains instructions for installing the Kronolith web-based
calendar application on your system.

For information on the capabilities and features of Kronolith, see the file
README_ in the top-level directory of the Kronolith distribution.


Prerequisites
=============

To function properly, Kronolith **requires** the following:

1. A working Horde installation.

   Kronolith runs within the `Horde Application Framework`_, a set of common
   tools for Web applications written in PHP.  You must install Horde before
   installing Kronolith.

   .. Important:: Kronolith H5 requires version 5.0+ of the Horde Framework -
                  earlier versions of Horde will **not** work.

   .. Important:: Be sure to have completed all of the steps in the
                  `horde/doc/INSTALL`_ file for the Horde Framework before
                  installing Kronolith. Many of Kronolith's prerequisites are
                  also Horde prerequisites. Additionally, many of Kronolith's
                  optional features are configured via the Horde install.

   .. _`Horde Application Framework`: http://www.horde.org/apps/horde

2. SQL support in PHP, or Kolab support in Horde

   Kronolith store its data in a backend - either an SQL database or a
   Kolab server. Build PHP with whichever SQL driver you require; see
   the Horde `horde/doc/INSTALL`_ file for more details on using databases
   with Horde, or the Kolab webclient documentation for how to set up
   Kronolith for Kolab.

3. Operating System with support for dates before 1970-01-01 [OPTIONAL]

   If you want to use dates earlier the January 1st 1970, for example
   birthdays, you need an Operating System that supports negative
   timestamps. Microsoft Windows does **not** support such dates.

4. The following PEAR package:
   (See `horde/doc/INSTALL`_ for instructions on installing PEAR package)

   .. Important:: If you are going to install Kronolith the recommended way,
                  i.e. using the PEAR installer, you can skip the remainder of
                  this section. Installing Kronolith through PEAR will
                  automatically download and install all required PEAR modules.

   a. Date

      Kronolith uses the Date package for various date calculations.

   b. Date_Holidays 0.21.0 [OPTIONAL]

      Kronolith can use the Date_Holidays package to show several sets of
      national and religious holidays and memorial days. Since Date_Holidays
      consists of a number of sub-packages, one for each country, you should
      install all packages at once::

         pear install Date_Holidays-alpha#all


Installing Kronolith
====================

The **RECOMMENDED** way to install Kronolith is using the PEAR installer.
Alternatively, if you want to run the latest development code or get the
latest not yet released fixes, you can install Kronolith from Git.

Installing with PEAR
~~~~~~~~~~~~~~~~~~~~

First follow the instructions in `horde/doc/INSTALL`_ to prepare a PEAR
environment for Horde and install the Horde Framework.

When installing Kronolith through PEAR now, the installer will automatically
install any dependencies of Kronolith too. If you want to install Kronolith
with all optional dependencies, but without the binary PECL packages that need
to be compiled, specify both the ``-a`` and the ``-B`` flag::

   pear install -a -B horde/kronolith

By default, only the required dependencies will be installed::

   pear install horde/kronolith

If you want to install Kronolith even with all binary dependencies, you need to
remove the ``-B`` flag. Please note that this might also try to install PHP
extensions through PECL that might need further configuration or activation in
your PHP configuration::

   pear install -a horde/kronolith

Installing from Git
~~~~~~~~~~~~~~~~~~~

See http://www.horde.org/source/git.php


Configuring Kronolith
=====================

1. Configuring Horde for Kronolith

   Kronolith requires a permanent ``Shares`` backend in Horde to manage
   calendars and to add events to calendars.  If you didn't setup a Share
   backend yet, go to the configuration interface, select Horde from the
   list of applications and select the ``Shares`` tab. Unless you are using
   Kolab, you should select ``SQL``.

2. Configuring Kronolith

   You must login to Horde as a Horde Administrator to finish the
   configuration of Kronolith.  Use the Horde ``Administration`` menu item to
   get to the administration page, and then click on the ``Configuration``
   icon to get the configuration page.  Select ``Calendar`` from the selection
   list of applications.  Fill in or change any configuration values as
   needed.  When done click on ``Generate Calendar Configuration`` to generate
   the ``conf.php`` file.  If your web server doesn't have write permissions
   to the Kronolith configuration directory or file, it will not be able to
   write the file.  In this case, go back to ``Configuration`` and choose one
   of the other methods to create the configuration file
   ``kronolith/config/conf.php``.

   Documentation on the format and purpose of the other configuration files in
   the ``config/`` directory can be found in each file. You may create
   ``*.local.php`` versions of these files if you wish to customize Kronolith's
   appearance and behavior. See the header of the configuration files for
   details and examples. The defaults will be correct for most sites.

3. Creating the database tables

   Once you finished the configuration in the previous step, you can create all
   database tables by clicking the ``DB schema is out of date.`` link in the
   Kronolith row of the configuration screen.

   Alternatively you creating the Kronolith database tables can be accomplished
   with horde's ``horde-db-migrate`` utility.  If your database is properly
   setup in the Horde configuration, just run the following::

      horde/bin/horde-db-migrate kronolith

4. Setting up reminder emails

   There are two kind of reminders sent to users, reminders on individual
   events with alarms, and daily agendas. Generally, if you set up cron jobs,
   you must have the PHP CLI installed (a CGI binary is not supported - ``php
   -v`` will report what kind of PHP binary you have).

   a. If you have not already set up the Horde Alarm system, you have to set up
      a cron entry for the Horde alarm script as documented in
      `horde/doc/INSTALL`_. This will send reminders for individual events.

   b. To send daily agendas, you must create a cron entry for
      ``kronolith-agenda``, and running the job once a day is recommended,
      e.g. at 2 a.m.::

         # Kronolith agenda
         0 2 * * * /usr/bin/kronolith-agenda

   If not installing Kronolith through PEAR of if PEAR's ``bin_dir``
   configuration doesn't point to ``/usr/bin/``, replace
   ``/usr/bin/kronolith-agenda`` with the path to the ``kronolith-agenda``
   script in your Kronolith installation.

5. Testing Kronolith

   Use Kronolith to create, modify, and delete events. Test at least the
   following:

   - Creating a new event
   - Creating a recurring event
   - Modifying an event
   - Deleting an event


Obtaining Support
=================

If you encounter problems with Kronolith, help is available!

The Horde Frequently Asked Questions List (FAQ), available on the Web at

  http://wiki.horde.org/FAQ

The Horde Project runs a number of mailing lists, for individual applications
and for issues relating to the project as a whole.  Information, archives, and
subscription information can be found at

  http://www.horde.org/community/mail

Lastly, Horde developers, contributors and users may also be found on IRC,
on the channel #horde on the Freenode Network (irc.freenode.net).

Please keep in mind that Kronolith is free software written by volunteers.
For information on reasonable support expectations, please read

  http://www.horde.org/community/support

Thanks for using Kronolith!

The Horde team


.. _README: README
.. _`horde/doc/INSTALL`: ../../horde/doc/INSTALL
.. _`horde/doc/TRANSLATIONS`: ../../horde/doc/TRANSLATIONS
