# Extendable Aggregator
Aggregates articles across multisite install

## Basic Usage
Network activated plugin. It allows publishing posts from one site to all other network sites. There isn't any setting required for this plugin, You will get meta box `Post Syndication` with the list of other network sites, you can choose the sites and it will start syncing. By default, this plugin supports Post, Comment, Attachment, and Terms. 

Also initially all synced posts will be linked to the source so that they will get updates. you can unlink by detaching from source.

## Step-by-step instructions
1. Log in to WordPress
1. Go to `Add New Post`
1. Prepare the post, Choose sites in `Post Syndication` on which you want to publish this post.
1. Click on Publish
1. Wait for 5 mins
1. Go to other network site dashboard to verify the post.

## Troubleshooting

### My content is not syndicating as expected

- This may happen due to long queues of syndication jobs enqueued. To overcome that, you can click the _Sync Now_ link beside the country name ( ie the _refresh_ icon ), which executes the syndication process instantly rather than via the queue system.

- Reason for that is that we have a hard limit ( filterable though ) of how many jobs can exist in a queue, for performance reasons, which should not be a problem in most cases. In cases where the queue is growing larger than expected, the plugin sends an email notification to the site admin email with the incident so it can be monitored/investigated further.

### Is there a dashboard where I can track the syndication process ?

- There are plans to create a dashboard of sorts to monitor the syndication queues and status, but nothing that has been started yet. It is recommended to use the same tracking method as we have in the job feeds plugin ( reporting to NewRelic custom events, and to plain emails when NewRelic is not present ( eg in cron containers ) ).

## Development tips

- The plugin has many usable filters/actions, feel free to use them before updating the main plugin files, to avoid any unexpected bugs or broken dependencies.
- The site repo has an integration plugin, cg-syndication, which controls the behavior of the plugin and hooks into many of the features/logic to customize it to the site needs. Look there first while debugging problems or tweaking the syndication logic.
- Most of the methods have inline documentation, and due to the complexity of the syndication process for Client, lots of the filters are already used in the integration plugin and would serve as good examples for future customization.

### Next / recommended enhancements

There are a few updates that can make the plugin more usable, accessible, and easy to monitor track:
- A Dashboard of sorts to display the list of posts marked for syndication
- Integration with NewRelic custom events / email notifications
- UI hints on the last time a post has been synced for each post
- ?
