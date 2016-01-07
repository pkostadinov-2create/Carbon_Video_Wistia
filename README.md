# Carbon_Video_Wistia
Extension lib for Wistia videos support with Carbon_Video lib

Requires the following line:

```$video_providers = array("Youtube", "Vimeo");```

to be replaced with:

```$video_providers = apply_filters('crb_video_providers', array("Youtube", "Vimeo"));```

In `lib/video.php`