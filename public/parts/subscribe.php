<div class="flex-me-down">
    <h1>Subscribe to Poly Phrases</h1>
    <p>Receive a daily phrase in multiple languages directly to your email.</p>
    <p>Simply enter your email, choose your languages, and start your daily language practice journey!</p>
    <form method="post" action="/subscribe" class="flex-me-down" id="subscribe-form">
        <label><input type="email" id="email" name="email" placeholder="your-email@example.com" required></label>
        <div class="languages-picker">
            <label><input type="checkbox" name="spanish" checked> Spanish</label>
            <label><input type="checkbox" name="german"> German</label>
            <label><input type="checkbox" name="italian"> Italian</label>
            <label><input type="checkbox" name="french"> French</label>
            <label><input type="checkbox" name="portuguese"> Portuguese</label>
            <label><input type="checkbox" name="norwegian"> Norwegian</label>
        </div>
        <div class="g-recaptcha" data-sitekey="<?php echo $_ENV['RECAPTCHA_SITE_KEY']; ?>"></div>
        <button class="button" type="submit">Subscribe - It's Free!</button>
    </form>
    <footer>
        polyphrases.com - <a target="_blank" href="/privacy.txt" title="Privacy policy">Privacy Policy</a>
    </footer>
</div>
