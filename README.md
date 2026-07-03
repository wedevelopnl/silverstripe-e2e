# Silverstripe E2E
Helper module providing Silverstripe-side tooling to support end-to-end (E2E)
testing of a functional Silverstripe website inside a CI pipeline.

## Note!
This module may be useful across various CI configurations, so feel free to use
it where applicable. Note that it is designed specifically to run inside our
internal GitLab CI default configuration/setup.

We will **not** provide support or implement features that are not supported by
our configuration.

This is the initial Silverstripe 6 foundation of the module; further E2E helpers
will be added over time.

## Tools

### Generate TinyMCE configuration
Silverstripe generates its TinyMCE configuration on the fly when requested. When
running a separate webserver container as a service inside the GitLab CI, those
files are generated on the php-fpm service, preventing the webserver from
accessing them. This build task generates the TinyMCE bundle files up front so
they can be baked into the webserver container during the build stage.

Run the task without a database:

`vendor/bin/sake tasks:generate-tinymce-combined --no-database`

It is also available via the browser at `/dev/tasks/generate-tinymce-combined`.
