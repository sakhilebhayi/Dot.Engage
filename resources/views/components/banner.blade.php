@props(['style' => session('flash.bannerStyle', 'success'), 'message' => session('flash.banner')])

<div x-data="{{ json_encode(['show' => true, 'style' => $style, 'message' => $message]) }}"
     :class="'banner is-' + style"
     class="banner"
     style="display: none;"
     role="status"
     x-show="show && message"
     x-on:banner-message.window="
        style = event.detail.style;
        message = event.detail.message;
        show = true;
     ">
    <div class="banner-in">
        <x-icon name="info" />
        <p x-text="message"></p>
        <button type="button" class="iconbtn" aria-label="Dismiss" x-on:click="show = false"><x-icon name="x" /></button>
    </div>
</div>
