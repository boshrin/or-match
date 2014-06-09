ZR-Match (Za Reconcilah)

Copyright (c) 2013-14, University of California, Berkeley
All rights reserved.

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:

1. Redistributions of source code must retain the above copyright notice,
   this list of conditions and the following disclaimer.

2. Redistributions in binary form must reproduce the above copyright notice,
   this list of conditions and the following disclaimer in the documentation
   and/or other materials provided with the distribution.

3. Neither the name of the copyright holder nor the names of its contributors
   may be used to endorse or promote products derived from this software
   without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.

About ZR-Match
==============
ZR-Match is an Identity Match engine designed for the Higher Education
community. An identity match engine accepts a request with various attributes
about a person (such as name, date of birth, etc) and attempts to determine if
a record matching such a person already exists.

**This release is experimental. It is not yet suitable for production use.**

ZR-Match is a configurable layer of PHP sitting on top of a database engine.
The PHP layer simply converts requests into a series of SQL queries, based on
how it is configured. An identity match operation is really just a search of a
database, and database engineers are generally pretty smart, so ZR-Match simply
uses a database engine for the heavy lifting.

ZR-Match is designed to use the PostgreSQL database. In addition to its general
awesomeness, it ships with a module that faciliates certain types of identity
matching queries. Other databases may or may not work with ZR-Match, however at
this time no support for other databases is planned.

ZR-Match communicates via the [CIFER ID Match API]
(https://spaces.internet2.edu/display/cifer/SOR-Registry+Strawman+ID+Match+API).
This is a RESTful JSON API designed to provide a standard way of performing
identity match requests. ZR-Match's implementation of this API is described in
more detail below.

Requests come into ZR-Match from one or more Systems of Record (SORs). Each SOR
presents a set of attributes about a person, and *must* include a unique
identifier for that person for that SOR (referred to as the "SORID"). ZR-Match
will then determine if it has a match for the request from data fram any SOR.

Installation
============
Apache & PHP
------------
Generally speaking, configuring Apache and PHP are outside the scope of these
instructions. PHP 5.3.2 or greater is required. Any reasonably recent version
of Apache should be sufficent.

Install ZR-Match wherever you normally install PHP applications. However, pay
attention to the following:

* Apache should be configured to operated over SSL, unless you are operating
  exclusively on a trusted network.
* Apache should point to (deliver) the `/webroot` directory. The other
  directories, *especially the `etc` directory*, should not be deliverable by
  Apache.
* You do not need to explicitly configure Apache to require authentication.
* You should set `date.timezone` in your `php.ini`, or you'll get a lot of
  noise in the log.

PostgreSQL
----------
Any recent (9.x) series of PostgreSQL should be sufficient, however you will
need the [fuzzystrmatch module]
(http://www.postgresql.org/docs/9.3/static/fuzzystrmatch.html)
installed.

If you are building PostgreSQL from scratch, you will need to

    $ make world
    # make install-world

to build these modules.

If you are using a distribution, you will likely need to install the appropriate
package.

Create a database, and perhaps a user (according to your local practices).
In this example, we'll call the database `zrmatch`. You will also need to enable
the `fuzzystrmatch` extension for the database.

    postgres=# create user match;
    CREATE ROLE
    postgres=# create database zrmatch owner match;
    CREATE DATABASE
    postgres=# create user match password 'somepassword';
    CREATE ROLE
    postgres=# alter database zrmatch owner to match;
    ALTER DATABASE
    postgres=# \c zrmatch;
    postgres=# create extension fuzzystrmatch;

Configuration
=============
For now, configuration is a manual process, and you must manually construct a
"matchgrid" (the database table that holds your match data) that correlates to
your configuration. (This is described in detail, below.) [Future enhancements]
(https://github.com/ucidentity/or-match/issues/6) will simplify this process.

ZR-Match uses [.ini format]
(http://www.php.net/manual/en/function.parse-ini-file.php)
for its configuration. Each section below corresponds to a section of the
`config.ini` file.

    [section:instance]
    ; keyword accepts a single value
    keyword = "value"
    ; keyword accepts multiple values, depending on key (like a hash)
    keyword['key'] = true
    ; keyword accepts multiple values, in order (like an array)
    keyword[] = 3

Values are in quotes unless boolean or integers.

A sample `config.ini` file can be found in the `etc` directory of the
distribution.

[attribute] Section
-------------------
Attribute definitions make up half of how ZR-Match determines what queries to
issue against the database. For now, the attributes must be defined manually.
([Issue #7] (https://github.com/ucidentity/or-match/issues/7))

There are two mandatory attributes: sor and sorid. You must define these.
([Issue #9] (https://github.com/ucidentity/or-match/issues/9))
See the example below for how to specify them.

Each attribute is specified as an instance of the attribute section, and
must be named uniquely.

### `alphanum` Keyword

This boolean keyword indicates only alphanumeric characters are significant for
this attribute. Non-alphanumeric characters are permitted in values but are
ignored for searching purposes.

For example, if set to true the strings `tyson-jones` and `tysonjones` are
equivalent.

Default if not specified: false

### `attribute` Keyword

Specifies the corresponding [CIFER API attribute name]
(https://spaces.internet2.edu/display/cifer/SOR-Registry+Core+Schema+Specification).
For attributes that accept types or other qualifiers, a colon is used to specify
the name. For example: `name:given` specifies first (given) name, and
`identifier:national` specifies Social Security Number (in the United States).

### `casesensitive` Keyword

This boolean keyword indicates if this value is case sensitive.

For example, if set to true the strings `Smith` and `smith` are not equivalent.

Default if not specified: false

### `column` Keyword

Specifies the corresponding column in the "matchgrid" table for this attribute.

To ensure future compatibility
([Issue #6] (https://github.com/ucidentity/or-match/issues/6) and
[Issue #7] (https://github.com/ucidentity/or-match/issues/7)), you should name
this column using the form `attr_attribute_name_group`, where `attribute_name`
corresponds to the value of the `attribute` keyword (with colons converted to
underscores) and `group` corresponds to the value of the `group` keyword, if
specified. For example: `attr_name_given_official` or
`attr_identifier_national`.

### `desc` Keyword

Description of the attribute. This is for documentation purposes only.

### `group` Keyword

The group keyword allows sets of attributes to be collected together. For
example, one could collect both official and preferred names by defining one
given name (and one family name) with group `official` and one with group
`preferred`.

### `invalidates` Keyword

This boolean keyword converts a canonical match to a potential match if the
specified attribute does not match.

For example, if a set of attributes is configured to be a canonical match on
name and SSN, and name and SSN exactly match, but date of birth is configured to
invalidate and the requested date of birth does *not* match the candidate
record, then the candidate will drop to a potential match even though name and
SSN would otherwise be a canonical match.

Default if not specified: false

### 'nullequivalents' Keyword

This boolean keyword determines if strings consisting of only blanks, zeroes,
and punctutation should be considered equivalent to null (not specified)
strings.

For example, if set to true the strings "000-00-0000" and " " will be treated
as if they were not specified.

Default if not specified: true

### `search` Keyword

The search keyword defines how this attribute can be searched. More than one
search type can be specified per attribute, how the search types are processed
is configured via the `confidence` Section.

* `distance`: Integer. The [Levenshtein distance]
  (http://en.wikipedia.org/wiki/Levenshtein_distance) of two strings for them to
  be considered matches. For example, the strings `smith` and `simth` have a
  distance of 2, since (simply) both `i` and `m` are out of place. Subject to
  modifiers much as `alphanum` and `casesensitive`.
* `exact`: Boolean. An exact search only matches when the exact string matches,
  subject to modifiers much as alphanum and casesensitive.
* `substr`: String of the format `"from,for"`. A substring match is like an
  exact match, but applies only to the specified substring. Note that
  *from* and *for* are specified as for the SQL `substring` function, so the
  leftmost character is `1`, not `0`. To perform a substring match against the
  first three characters of a string, use `"1,3"`.

Addition search types may be supported in the future
([Issue #12] (https://github.com/ucidentity/or-match/issues/12),
[Issue #13] (https://github.com/ucidentity/or-match/issues/13), and
[Issue #23] (https://github.com/ucidentity/or-match/issues/23)).

### Example

    ; sor is mandatory
    [attribute:sor]
    desc = "System of Record"
    column = "sor"
    attribute = "sor"
    casesensitive = true
    search['exact'] = true
    
    ; sorid is mandatory
    [attribute:sorid]
    desc = "System of Record Identifier"
    column = "sorid"
    attribute = "identifier:sor"
    casesensitive = true
    search['exact'] = true
    
    [attribute:firstname]
    desc = "Given Name (official)"
    column = "attr_name_given_official"
    attribute = "name:given"
    group = "official"
    casesensitive = false
    search['exact'] = true
    search['distance'] = 2
    search['substr'] = "1,3"
    
    [attribute:lastname]
    desc = "Family Name (official)"
    column = "attr_name_family_official"
    attribute = "name:family"
    group = "official"
    casesensitive = false
    search['exact'] = true
    search['distance'] = 2
    
    [attribute:dob]
    desc = "DoB"
    column = "attr_date_of_birth"
    attribute = "dateOfBirth"
    alphanum = true
    search['exact'] = true
    search['distance'] = 2

[auth] Section
--------------
This section controls how ZR-Match handles authentication and authorization
of clients.

### `method` Keyword

Value must be set to one of the following:

* `BasicAuth`: the API will expect to use Basic Auth to authenticate the client.
  See "Configuring matchauth", below.

Currently, no other options are supported.

### Example

    [auth]
    method = "BasicAuth"

[confidence] Section
--------------------
Confidence sets make up the other half of how ZR-Match determines what queries
to issue against the database. There are two instances of the confidence
section: `canonical` and `potential`. Within each instance, zero or more sets
of attributes are defined to create the parameters by which search queries are
issued. Sets may be given arbitrary names.

Each attribute used within a confidence set must be defined in a corresponding
[attribute] section, as described above. An error will be thrown if an
undefined attribute is referenced.

Canonical and potential matches operate somewhat differently.

### [confidence:canonical]

A canonical match occurs when each attribute defined in a set (as provided in
a search request) matches exactly (subject to modifiers such as `casesensitive`
and `alphanum`) a record in the database. Only exact matches are tried,
regardless of what search types are configured for the attribute.

If a given canonical attribute set returns more than one candidate, the search
result will automatically be converted to a potential match. Furthermore, all
canonical sets are searched. That is, searching does not stop after a single
match is found. If more than one candidate is found, the search result will
again automatically be converted to a potential match.

### [confidence:potential]

If no canonical matches are found, then potential matches will be checked.
A potential match occurs when each attribute defined in a set matches
according to the searchtype specified for that attribute. For example, a set
defined like

    set1['ssn'] = "exact"
    set1['firstname'] = "exact"
    set1['lastname'] = "exact"
    set1['dob'] = "distance"

will result in a potential match if `ssn`, `firstname`, and `lastname` all
match exactly (subject to modifiers such as `casesensitive` and `alphanum`),
*and* `dob` matches within the distance specified in its definition (in the
previous examples, that was set to `2`). So if a search request comes in for

    999001234 / Pat / Lee / 1983-03-17

and the database holds

    999001234 / Pat / Lee / 1983-03-18

a potential match is found. However

    999001234 / Patricia / Lee / 1983-03-17

will not match since firstname did not match exactly.

All potential match queries will be tried, even if a match is found.

### Example

    [confidence:canonical]
    ; Any of these sets will create a canonical match when all of their
    ; constituent attributes (defined above in a corresponding [attribute:foo]
    ; section) match exactly.
    set1[] = "sor"
    set1[] = "sorid"
    set1[] = "lastname"
    
    set2[] = "ssn"
    set2[] = "firstname"
    set2[] = "lastname"
    set2[] = "dob"
    
    [confidence:potential]
    ; If there are no canonical matches, then try for potential matches
    set1['ssn'] = "exact"
    set1['firstname'] = "exact"
    set1['lastname'] = "exact"
    set1['dob'] = "distance"
    
    set2['ssn'] = "distance"
    set2['firstname'] = "substr"
    set2['lastname'] = "exact"
    set2['dob'] = "exact"

[database] Section
------------------
This section controls how ZR-Match connects to the database.

### `database` Keyword

The name of the database to use.

### `host` Keyword

The hostname of the database server.

### `password` Keyword

The password used to authenticate to the database.

### `type` Keyword

The type of database in use:

* `postgres`: Currently the only supported type.

### `user` Keyword

The username uesd to authenticate to the database.

### Example

    [database]
    type = "postgres"
    host = "localhost"
    database = "zrmatch"
    user = "match"
    password = "somepass"

[logging] Section
-----------------
This section controls how ZR-Match logs requests and events.

### `logfile` Keyword

If `method` is set to `file`, this specifies which file logs are written to.

### `method` Keyword

Must be set to one of the following values:

* `file`: Write logs to the specified file.
* `syslog`: Use syslog (currently all logs go to `daemon.info`).

### `trace` Keyword

Specific logging/tracing can be enabled to facilitate debugging and other tasks.

The "trace" keyword accepts the following boolean keys:

* `sql`: Logs generated SQL, including parameters passed. Warning: Sensitive
information may be logged.

### Example

    [logging]
    method = "syslog"

[referenceid] Section
---------------------
A *reference identifier* is the unique identifier managed by ZR-Match
for each person it knows about. Obtaining a reference identifier is the primary
goal of a match request -- if an existing person is found, the existing
reference identifier for that person is returned. If no existing person is
found, a new reference identifier is obtained.

### `method` Keyword

This determines how reference identifiers are assigned:

* `uuid`: Generate a [universally unique identifier]
(http://en.wikipedia.org/wiki/Universally_unique_identifier).
* `sequence`: Select the next number from a sequence.

For sequence-based reference identifiers, the sequence is expected to be named
`reference_id_seq`. If this sequence does not exist the first time a reference
identifier is assigned, it will be created.

### `responsetype` Keyword

The reference identifier is ordinarily returned as a reference identifier as per
the CIFER ID Match API. It is also possible to return the reference identifier
as an additional identifier type, for example "enterprise" to indicate it is
also used as a system-to-system identifier (not known to the user) within the
institution.

The value for this keyword is the type of the identifier.

You probably don't need to set this option until you have a use case that
requires it.

### Example

    [referenceid]
    method = "uuid"

[sors] Section
-------------
This section defines ZR-Match behavior on a per-SOR basis. Note that authnz is
controlled by the `matchauth` table, described below.

The keyword for this section is the label of the SOR. For each SOR, a key can
be specified corresponding to the options described here.

### `resolution` Key

Determines how potential matches (that is, a search request that generates one
or more potential matches and no exact matches) are handled.

* `external`: The request is recorded in `matchgrid` unresolved (ie: no
  reference identifier is assigned), and `202 Accepted` is returned to the
  client. An out of band process is required to resolve the request. (See
  Integration, below.)
* `interactive`: The SOR is capable of processing potential matches, and so the
  set of candidates are returned as part of a `300 Multiple Choices` response.
  No record is made in `matchgrid`. The SOR must re-submit the request as a
  CIFER Forced Reconciliation Request, or otherwise provide sufficient
  attributes to create an exact match.

Default if not specified: external

### Example

    ; The HRMS SOR is capable of interactive resolution
    hrms['resolution'] = "interactive"

Constructing `matchgrid`
========================
`matchgrid` is the core table used by ZR-Match to store records from SORs and
the reference identifier used to link SOR records for the same person together.

Currently, `matchgrid` must be created manually, though in the future a utility
will be provided to automate this process ([Issue #6]
(https://github.com/ucidentity/or-match/issues/6)).

### Columns

The following columns are required:

* `id`: Primary key, defined as a serial.
* `sor`: Holds the label for the SOR for this record (eg: `SIS`, `HRMS`, etc).
* `sorid`: Holds the unique identifier from the SOR for this record.
* `reference_id`: Once a record is matched, this column holds the reference
  identifier that uniquely identifies the person.
* `request_time`: The time the request was made.
* `resolution_time`: The time the request was resolved. If null, the request
  generated potential matches and is pending resolution by an administrator.

Then, one column is required for each attribute that is defined in `config.ini`.
The name of this column *must* match the `column` keyword in the corresponding
[Attribute] section. For future compatibility ([Issue #7]
(https://github.com/ucidentity/or-match/issues/7)), each column *should* be
named following the format `attr_attribute_name_group`, where `attribute_name`
corresponds to the value of the `attribute` keyword (with colons converted to
underscores) and `group` corresponds to the value of the `group` keyword, if
specified. For example: `attr_name_given_official` or
`attr_identifier_national`.

### Indexes

The following indexes and constraints should be created:

* `id`: Primary key
* `sor`: Indexed (`matchgrid_i1`)
* `sorid`: Indexed (`matchgrid_i2`)
* `sor`+`sorid`: Unique and indexed (`matchgrid_i3`)
* `reference_id`: Indexed (`matchgrid_i4`)
* `resolution_time`: Indexed, nulls first (`matchgrid_i5`)

Then, each attribute column defined should be indexed. If the column is case
insensitive, it should be indexed for lowercase searches.

For future compatibility, you should name each index using the name provided
in parentheses. For attribute indexes, use the format `mg_attributename_ai` but
without the `attr_` prefix. For example: `mg_name_given_official_ai`.

### Sample SQL

The following sample SQL will create `matchgrid` and its accompanying indexes
with these attributes: SSN, given name, family name, date of birth, affiliate
identifier, employee identifier, and student identifier.

    CREATE TABLE matchgrid (
     -- Base columns
     id                            SERIAL PRIMARY KEY,
     sor                           VARCHAR(20) NOT NULL,
     sorid                         VARCHAR(40) NOT NULL,
     reference_id                  VARCHAR(40),
     request_time                  TIMESTAMP NOT NULL,
     resolution_time               TIMESTAMP,
     -- Attribute columns (make sure they are wide enough for your data!)
     attr_identifier_national      VARCHAR(16),
     attr_name_given_official      VARCHAR(80),
     attr_name_family_official     VARCHAR(80),
     attr_date_of_birth            VARCHAR(16),   -- String to facilitate distance check
     attr_identifier_sor_affiliate VARCHAR(40),
     attr_identifier_sor_employee  VARCHAR(40),
     attr_identifier_sor_student   VARCHAR(40),
     -- Constraints
     UNIQUE (sor,sorid) -- it may make sense to add this manually after populating matchgrid
    );
    
    -- Static indexes
    CREATE INDEX matchgrid_i1 ON matchgrid(sor);
    CREATE INDEX matchgrid_i2 ON matchgrid(sorid);
    CREATE INDEX matchgrid_i3 ON matchgrid(sor,sorid);
    CREATE INDEX matchgrid_i4 ON matchgrid(reference_id);      -- all searches query IS NOT NULL
    CREATE INDEX matchgrid_i5 ON matchgrid(resolution_time NULLS FIRST);  -- query IS NULL for pending matches (could also query referenceid, but this allows an "ignore" option that doesn't require a fuzzy match to be resolved)
    
    -- Dynamic indexes (created per attribute)
    CREATE INDEX mg_identifier_national_ai ON matchgrid(attr_identifier_national);
    CREATE INDEX mg_name_given_official_ai ON matchgrid(lower(attr_name_given_official));
    CREATE INDEX mg_name_family_official_ai ON matchgrid(lower(attr_name_family_official));
    CREATE INDEX mg_date_of_birth_ai ON matchgrid(attr_date_of_birth);
    CREATE INDEX mg_identifier_sor_affiliate_ai ON matchgrid(attr_identifier_sor_affiliate);
    CREATE INDEX mg_identifier_sor_employee_ai ON matchgrid(attr_identifier_sor_employee);
    CREATE INDEX mg_identifier_sor_student_ai ON matchgrid(attr_identifier_sor_student);
    
    -- If not added at table create time
    ALTER TABLE matchgrid ADD CONSTRAINT matchgrid_c1 UNIQUE (sor,sorid);

Configuring `matchauth`
=======================
`matchauth` is the table used by ZR-Match to authenticate and authorize users.
(Credentials are sent to ZR-Match via Basic Auth.)

For now, records must be added to this table manually ([Issue #19]
(https://github.com/ucidentity/or-match/issues/19)).

### Columns

* `apiuser`: The username.
* `apikey`: The "key" or password.
* `sor`: The SOR for which this user is authorized to assert records. (This
  corresponds to the `sor` column in `matchgrid` and the `sor` attribute sent
  as part of a request.) Use `*` to indicate the user is authorized for all
  SORs.

### Sample SQL

    CREATE TABLE matchauth (
     apiuser    VARCHAR(80),
     apikey     VARCHAR(80),
     sor        VARCHAR(20)
    );
    
    -- Indexes aren't required for this table if there won't be that many users

Integration
===========
ZR-Match is based on the [SOR-Registry Strawman ID Match API]
(https://spaces.internet2.edu/display/cifer/SOR-Registry+Strawman+ID+Match+API),
and this section assumes familiarity with that document.

* ZR-Match is an **independent** implementation that matches against each SOR's
  representation of attributes. It does not maintain a "golden" record.
* ZR-Match supports both **synchronous** and **asynchronous** match resolution.
* ZR-Match is designed as a **standalone** service, though it can be placed
  behind a Person Registry. Matching can therefore be performed before or at
  the Person Registry.

XXX

Bulk Loading Records
====================

Bug Reports and Issue Tracking
==============================

Changelog
=========