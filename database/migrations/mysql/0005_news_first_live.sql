-- When an Industry Updates article first went live, kept once a publish or an unpublish would otherwise lose it
-- (a reschedule moves published_at on, a takedown of a scheduled time clears it): its slug never changes after.
ALTER TABLE news_articles ADD COLUMN first_live_at INT NULL;
