<?php
/**
 * Seed blog: categories, tags, and 12+ SEO-optimized articles.
 * Run after migrate-blog.php: php seed-blog.php
 */
require_once __DIR__ . '/app/init.php';
require_cli();
$db = Database::getInstance();
$pdo = $db->getConnection();

// Categories
$categories = [
    ['smm-tips', 'SMM Tips', 'Guides and tips for social media marketing. Cheap SMM panel strategies.'],
    ['instagram-growth', 'Instagram Growth', 'How to grow Instagram followers, likes, and engagement.'],
    ['youtube-marketing', 'YouTube Marketing', 'YouTube views, subscribers, and channel growth.'],
    ['tiktok-viral', 'TikTok Viral', 'TikTok followers, likes, and going viral.'],
    ['reseller-guide', 'Reseller Guide', 'SMM reseller panel and API for agencies.'],
    ['api-automation', 'API & Automation', 'SMM API integration and automation.'],
];
foreach ($categories as $c) {
    $pdo->exec("INSERT IGNORE INTO blog_categories (slug, name, meta_description) VALUES ('{$c[0]}', " . $pdo->quote($c[1]) . ", " . $pdo->quote($c[2]) . ")");
}
echo "Categories OK\n";

// Tags
$tags = [
    'instagram', 'youtube', 'tiktok', 'followers', 'likes', 'views', 'smm-panel', 'reseller', 'api', 'crypto', 'cheap-smm', 'growth', 'marketing', 'social-media',
];
foreach ($tags as $t) {
    $name = ucfirst(str_replace('-', ' ', $t));
    $pdo->exec("INSERT IGNORE INTO blog_tags (slug, name) VALUES ('$t', " . $pdo->quote($name) . ")");
}
echo "Tags OK\n";

// Helper: get category id by slug
$getCatId = function ($slug) use ($db) {
    $r = $db->fetch("SELECT id FROM blog_categories WHERE slug = ?", [$slug]);
    return $r ? (int)$r['id'] : null;
};
$getTagIds = function (array $slugs) use ($db) {
    $ids = [];
    foreach ($slugs as $s) {
        $r = $db->fetch("SELECT id FROM blog_tags WHERE slug = ?", [$s]);
        if ($r) $ids[] = (int)$r['id'];
    }
    return $ids;
};

$now = date('Y-m-d H:i:s');
$articles = [
    [
        'slug' => 'what-is-smm-panel-cheapest-guide',
        'title' => 'What Is an SMM Panel? The Cheapest SMM Panel Guide 2024',
        'meta_description' => 'What is an SMM panel? Learn how the cheapest SMM panel works: Instagram followers, YouTube views, TikTok likes. SMM Turk guide.',
        'meta_keywords' => 'SMM panel, cheapest SMM panel, what is SMM panel, social media marketing panel, buy followers',
        'excerpt' => 'An SMM panel is a reseller platform where you buy social media growth services like Instagram followers, YouTube views, and TikTok likes at the lowest prices.',
        'category' => 'smm-tips',
        'tags' => ['smm-panel', 'cheap-smm', 'instagram', 'youtube', 'tiktok'],
        'body' => '<p>An <strong>SMM panel</strong> (Social Media Marketing panel) is an online platform that lets you buy engagement for social networks: followers, likes, views, and more. Panels connect you to providers who deliver real or high-quality services at low cost.</p>
<h2>Why Use the Cheapest SMM Panel?</h2>
<p>Using the <strong>cheapest SMM panel</strong> helps you grow your accounts without spending a lot. Prices often start from $0.01 per action. SMM Turk is one of the most affordable panels, with instant start and 24/7 support.</p>
<h2>What Can You Buy?</h2>
<ul><li>Instagram followers, likes, comments, story views</li><li>YouTube views, subscribers, likes</li><li>TikTok followers, likes, views</li><li>Facebook, Twitter, Telegram, and more</li></ul>
<h2>How to Get Started</h2>
<p>Register on SMM Turk, add funds (crypto only: USDT, BTC, ETH), then place your first order. Most orders start within minutes. You can also use our <strong>reseller panel</strong> or <strong>API</strong> to sell services to your own clients.</p>',
        'reading_time_min' => 4,
    ],
    [
        'slug' => 'how-to-get-instagram-followers-cheap',
        'title' => 'How to Get Instagram Followers Cheap: Best SMM Panel 2024',
        'meta_description' => 'Get Instagram followers cheap with the best SMM panel. Real and fast delivery. Tips for Instagram growth and engagement.',
        'meta_keywords' => 'Instagram followers cheap, buy Instagram followers, SMM panel Instagram, get followers',
        'excerpt' => 'Getting Instagram followers cheap is easy with an SMM panel. Choose a reliable panel like SMM Turk for fast delivery and low prices.',
        'category' => 'instagram-growth',
        'tags' => ['instagram', 'followers', 'cheap-smm', 'smm-panel', 'growth'],
        'body' => '<p>Want more <strong>Instagram followers</strong> without breaking the bank? An <strong>SMM panel</strong> is the most cost-effective way to grow your account.</p>
<h2>Why Buy Instagram Followers?</h2>
<p>More followers improve credibility and reach. With the <strong>cheapest SMM panel</strong>, you can boost numbers quickly and affordably. SMM Turk offers Instagram followers starting at very low rates with fast delivery.</p>
<h2>Tips for Best Results</h2>
<ul><li>Combine with organic content: post regularly and use hashtags.</li><li>Order in smaller batches for a natural growth curve.</li><li>Use likes and comments too for better engagement.</li></ul>
<h2>Where to Buy</h2>
<p>SMM Turk supports Instagram followers, likes, comments, story views, and more. Add funds with crypto (USDT, BTC, ETH) and place your order in minutes. 24/7 support included.</p>',
        'reading_time_min' => 3,
    ],
    [
        'slug' => 'youtube-views-subscribers-smm-panel',
        'title' => 'Buy YouTube Views and Subscribers: SMM Panel Guide',
        'meta_description' => 'Buy YouTube views and subscribers from a trusted SMM panel. High retention views, real subscribers. Grow your channel fast.',
        'meta_keywords' => 'YouTube views, YouTube subscribers, buy YouTube views, SMM panel YouTube',
        'excerpt' => 'Grow your YouTube channel with views and subscribers from an SMM panel. High-quality delivery and competitive prices.',
        'category' => 'youtube-marketing',
        'tags' => ['youtube', 'views', 'followers', 'smm-panel', 'marketing'],
        'body' => '<p>Growing a <strong>YouTube</strong> channel takes time. An <strong>SMM panel</strong> can give you a head start with <strong>views</strong> and <strong>subscribers</strong> at low cost.</p>
<h2>YouTube Views</h2>
<p>Views from a good panel often come with high retention and from real-looking sources. This helps your video rank and look more popular.</p>
<h2>YouTube Subscribers</h2>
<p>Subscriber services can add real or high-quality accounts to your channel. Combined with strong content, this boosts credibility.</p>
<h2>Using SMM Turk for YouTube</h2>
<p>SMM Turk offers YouTube views, subscribers, likes, and comments. Place orders from the dashboard or via <strong>API</strong>. Payment is crypto only (USDT, BTC, ETH). Support is available 24/7.</p>',
        'reading_time_min' => 3,
    ],
    [
        'slug' => 'tiktok-followers-likes-go-viral',
        'title' => 'TikTok Followers and Likes: How to Go Viral with SMM',
        'meta_description' => 'Get TikTok followers and likes from the cheapest SMM panel. Boost your TikTok presence and go viral faster.',
        'meta_keywords' => 'TikTok followers, TikTok likes, buy TikTok followers, TikTok viral, SMM panel',
        'excerpt' => 'TikTok growth is easier with an SMM panel. Buy followers and likes to boost your profile and reach more people.',
        'category' => 'tiktok-viral',
        'tags' => ['tiktok', 'followers', 'likes', 'smm-panel', 'growth'],
        'body' => '<p><strong>TikTok</strong> is one of the fastest-growing platforms. To stand out, many creators use an <strong>SMM panel</strong> to get <strong>followers</strong> and <strong>likes</strong> quickly.</p>
<h2>Why TikTok SMM Works</h2>
<p>Initial engagement (followers and likes) can help your videos get picked up by the algorithm. More engagement often leads to more organic reach.</p>
<h2>What to Order</h2>
<p>Start with followers and likes. Some panels also offer views and comments. SMM Turk has a wide range of TikTok services at low prices.</p>
<h2>Best Practices</h2>
<p>Post consistently, use trending sounds and hashtags, and combine bought engagement with real content. That way your account grows in a balanced way.</p>',
        'reading_time_min' => 3,
    ],
    [
        'slug' => 'smm-reseller-panel-start-making-money',
        'title' => 'SMM Reseller Panel: How to Start and Make Money',
        'meta_description' => 'Start your own SMM business with an SMM reseller panel. White-label services, API, and support. SMM Turk reseller guide.',
        'meta_keywords' => 'SMM reseller panel, reseller panel, SMM business, resell SMM services',
        'excerpt' => 'An SMM reseller panel lets you sell social media services to clients. Learn how to start and profit with SMM Turk reseller options.',
        'category' => 'reseller-guide',
        'tags' => ['reseller', 'smm-panel', 'api', 'marketing'],
        'body' => '<p>An <strong>SMM reseller panel</strong> lets you sell social media services (followers, likes, views) to your own clients. You buy at wholesale and sell at your price.</p>
<h2>Why Resell SMM?</h2>
<p>Demand for social media growth is high. Agencies, freelancers, and small businesses need followers and engagement. As a reseller, you can serve them and earn a margin.</p>
<h2>What You Need</h2>
<ul><li>A panel that offers reseller or child-panel options</li><li>API access for automation</li><li>Support and reliable delivery</li></ul>
<p><strong>SMM Turk</strong> offers reseller-friendly features: competitive rates, API, and 24/7 support. You can start with a small balance and scale up.</p>
<h2>How to Start</h2>
<p>Register, add funds (crypto), and either use the main panel to serve clients manually or integrate via API. Some panels also offer a child panel so you can give your clients their own login.</p>',
        'reading_time_min' => 4,
    ],
    [
        'slug' => 'smm-api-integration-automation',
        'title' => 'SMM API Integration: Automate Your Social Media Orders',
        'meta_description' => 'Integrate SMM panel API for automation. Place orders programmatically. Documentation and examples for SMM Turk API.',
        'meta_keywords' => 'SMM API, SMM panel API, API integration, automate SMM orders',
        'excerpt' => 'Use the SMM panel API to automate orders and integrate with your app or website. Fast and reliable API from SMM Turk.',
        'category' => 'api-automation',
        'tags' => ['api', 'smm-panel', 'reseller', 'automation'],
        'body' => '<p>An <strong>SMM API</strong> lets you place and manage orders from your own system. No need to use the panel manually for every order.</p>
<h2>Why Use the API?</h2>
<p>If you run a reseller site, bot, or internal tool, the API saves time and reduces errors. You can create orders, check status, and manage balance programmatically.</p>
<h2>What You Can Do</h2>
<ul><li>Create orders (service, link, quantity)</li><li>Check order status</li><li>Get service list and prices</li><li>Check balance</li></ul>
<h2>SMM Turk API</h2>
<p>SMM Turk provides a simple API with clear documentation. Get your API key from the dashboard and start integrating. Support is available if you need help.</p>',
        'reading_time_min' => 3,
    ],
    [
        'slug' => 'cheapest-smm-panel-crypto-payment',
        'title' => 'Cheapest SMM Panel with Crypto Payment: USDT, BTC, ETH',
        'meta_description' => 'Pay with crypto at the cheapest SMM panel. USDT, BTC, ETH, BNB, SOL accepted. Fast and secure deposits.',
        'meta_keywords' => 'SMM panel crypto, pay SMM with USDT, Bitcoin SMM panel, cryptocurrency SMM',
        'excerpt' => 'The cheapest SMM panels often accept only crypto. Learn how to add funds with USDT, BTC, or ETH safely.',
        'category' => 'smm-tips',
        'tags' => ['cheap-smm', 'crypto', 'smm-panel'],
        'body' => '<p>Many <strong>cheap SMM panels</strong> accept only <strong>cryptocurrency</strong>. This keeps costs low and payments fast. SMM Turk accepts <strong>USDT</strong> (ERC20/TRC20), <strong>BTC</strong>, <strong>ETH</strong>, <strong>BNB</strong>, and <strong>SOL</strong>.</p>
<h2>Why Crypto?</h2>
<p>Crypto payments reduce fees and chargebacks. That allows panels to offer the <strong>cheapest SMM</strong> rates. For you, it means lower prices per order.</p>
<h2>How to Add Funds</h2>
<p>Log in, go to Add Funds, choose your coin, and send the exact amount to the address shown. After confirmation, balance is updated. Support can help if there is a delay.</p>
<h2>Safety</h2>
<p>Use only the address shown in your panel. Double-check the network (e.g. ERC20 vs TRC20 for USDT). Never send from an exchange without checking minimums and network.</p>',
        'reading_time_min' => 3,
    ],
    [
        'slug' => 'instagram-likes-comments-engagement',
        'title' => 'Instagram Likes and Comments: Boost Engagement Fast',
        'meta_description' => 'Buy Instagram likes and comments to boost engagement. Best SMM panel for Instagram. Fast delivery.',
        'meta_keywords' => 'Instagram likes, Instagram comments, buy likes, engagement SMM',
        'excerpt' => 'Likes and comments make your posts more visible. Get them from a trusted SMM panel for instant engagement boost.',
        'category' => 'instagram-growth',
        'tags' => ['instagram', 'likes', 'growth', 'smm-panel'],
        'body' => '<p>Besides <strong>followers</strong>, <strong>likes</strong> and <strong>comments</strong> are key for Instagram growth. They signal to the algorithm that your content is worth showing to more people.</p>
<h2>Why Likes and Comments Matter</h2>
<p>Posts with more engagement get better reach. Buying initial likes and comments can kickstart this cycle. Combine with good content for best results.</p>
<h2>What to Order</h2>
<p>Most panels offer likes per post, story views, and sometimes comments. SMM Turk has multiple Instagram services so you can match your goal.</p>
<h2>Best Practice</h2>
<p>Order in line with your usual engagement level. Too big a jump can look unnatural. Spread orders over time and keep posting quality content.</p>',
        'reading_time_min' => 3,
    ],
    [
        'slug' => 'best-smm-panel-comparison-2024',
        'title' => 'Best SMM Panel Comparison 2024: Price, Speed, Support',
        'meta_description' => 'Compare the best SMM panels in 2024. Price, delivery speed, and support. Why SMM Turk is among the cheapest and fastest.',
        'meta_keywords' => 'best SMM panel, SMM panel comparison, cheapest SMM 2024',
        'excerpt' => 'We compare top SMM panels by price, speed, and support. Find the best and cheapest option for your needs.',
        'category' => 'smm-tips',
        'tags' => ['smm-panel', 'cheap-smm', 'marketing'],
        'body' => '<p>Choosing the <strong>best SMM panel</strong> depends on price, speed, variety, and support. Here is what to look for.</p>
<h2>Price</h2>
<p>The <strong>cheapest SMM panel</strong> is not always the best if delivery is slow or support is missing. Compare price per 1000 (followers, likes, or views) across a few panels.</p>
<h2>Speed</h2>
<p>Many panels start orders within minutes. SMM Turk focuses on fast start and reliable completion. Check average times for the services you need.</p>
<h2>Support</h2>
<p>24/7 support helps when you have a question or an order issue. SMM Turk offers ticket support so you can get help anytime.</p>
<h2>Summary</h2>
<p>SMM Turk combines low prices, fast delivery, and 24/7 support. It is a solid choice for both personal growth and reselling.</p>',
        'reading_time_min' => 4,
    ],
    [
        'slug' => 'social-media-marketing-for-small-business',
        'title' => 'Social Media Marketing for Small Business: SMM Panel Tips',
        'meta_description' => 'Use an SMM panel for small business social media marketing. Grow Instagram, Facebook, YouTube on a budget.',
        'meta_keywords' => 'social media marketing, small business SMM, Instagram business, cheap marketing',
        'excerpt' => 'Small businesses can grow on social media without a big budget. An SMM panel gives you affordable followers and engagement.',
        'category' => 'smm-tips',
        'tags' => ['social-media', 'marketing', 'smm-panel', 'growth'],
        'body' => '<p><strong>Social media marketing</strong> is essential for small businesses. An <strong>SMM panel</strong> lets you get <strong>followers</strong>, <strong>likes</strong>, and <strong>views</strong> at a fraction of the cost of ads.</p>
<h2>Where to Focus</h2>
<p>Start with one or two platforms: e.g. Instagram and Facebook, or YouTube. Order followers and engagement to build social proof, then add organic content.</p>
<h2>Budget-Friendly</h2>
<p>With the <strong>cheapest SMM panel</strong>, you can allocate more budget to content or ads. SMM Turk has services starting from $0.01 so you can test without risk.</p>
<h2>Consistency</h2>
<p>Use the panel to boost numbers, but post regularly and reply to comments. That way your account stays active and trustworthy.</p>',
        'reading_time_min' => 3,
    ],
    [
        'slug' => 'instagram-story-views-reach',
        'title' => 'Instagram Story Views: How to Increase Reach',
        'meta_description' => 'Get more Instagram story views with an SMM panel. Increase reach and engagement on stories. Fast delivery.',
        'meta_keywords' => 'Instagram story views, story views, Instagram reach, SMM panel',
        'excerpt' => 'Story views help your account stay visible. Buy story views from a panel to boost reach and engagement.',
        'category' => 'instagram-growth',
        'tags' => ['instagram', 'views', 'growth', 'smm-panel'],
        'body' => '<p><strong>Instagram story views</strong> can boost your reach and make your profile more active. Many panels offer story view services.</p>
<h2>Why Story Views Help</h2>
<p>Stories appear at the top of the feed. More views can lead to more profile visits and followers. They also show that your account is active.</p>
<h2>How to Order</h2>
<p>Choose the story views service in the panel, enter your profile or story link, set quantity, and place the order. Delivery is usually fast.</p>
<h2>Combine with Content</h2>
<p>Post stories regularly and use stickers and polls to encourage replies. Bought views work best when you also create engaging content.</p>',
        'reading_time_min' => 3,
    ],
    [
        'slug' => 'affiliate-program-smm-earn-money',
        'title' => 'SMM Panel Affiliate Program: Earn Money Referring Users',
        'meta_description' => 'Earn with SMM Turk affiliate program. Refer users and get commission. How to join and maximize earnings.',
        'meta_keywords' => 'SMM affiliate, affiliate program, earn money SMM, referral commission',
        'excerpt' => 'SMM Turk affiliate program pays you for every referred user. Share your link and earn commission on their orders.',
        'category' => 'reseller-guide',
        'tags' => ['reseller', 'smm-panel', 'marketing', 'growth'],
        'body' => '<p>Besides reselling, you can earn with an <strong>affiliate program</strong>. SMM Turk offers referral commissions when you bring new users.</p>
<h2>How It Works</h2>
<p>Get your unique referral link from the panel. Share it on your site, social media, or with friends. When someone signs up and adds funds, you earn a percentage of their spend.</p>
<h2>Who It Is For</h2>
<p>Bloggers, YouTubers, marketers, and anyone with an audience interested in social media growth can promote the panel and earn.</p>
<h2>How to Start</h2>
<p>Log in to SMM Turk, go to the Affiliates section, copy your link, and start sharing. Track clicks and earnings in the dashboard.</p>',
        'reading_time_min' => 3,
    ],
    [
        'slug' => 'why-smm-turk-cheapest-fastest-panel',
        'title' => 'Why SMM Turk Is the Cheapest and Fastest SMM Panel',
        'meta_description' => 'Why SMM Turk is one of the cheapest and fastest SMM panels. Low prices, instant start, 24/7 support. Turkey and worldwide.',
        'meta_keywords' => 'SMM Turk, cheapest SMM panel, fastest SMM panel, Turkey SMM',
        'excerpt' => 'SMM Turk offers the cheapest SMM panel prices and fast delivery. Learn why thousands of users choose us.',
        'category' => 'smm-tips',
        'tags' => ['smm-panel', 'cheap-smm', 'growth', 'marketing'],
        'body' => '<p><strong>SMM Turk</strong> is built to be one of the <strong>cheapest</strong> and <strong>fastest</strong> SMM panels available. Here is why.</p>
<h2>Low Prices</h2>
<p>We work directly with providers and keep margins low. Prices start from $0.01 for many services. That makes us one of the most affordable panels for Instagram, YouTube, TikTok, and more.</p>
<h2>Fast Delivery</h2>
<p>Most orders start within minutes. We prioritize speed so your growth does not wait. You can track status in real time in My Orders.</p>
<h2>24/7 Support</h2>
<p>Questions or issues? Open a ticket anytime. Our support team is available around the clock to help you.</p>
<h2>Reseller & API</h2>
<p>We support resellers and developers with API and reseller options. You can sell our services or integrate them into your own platform. Join SMM Turk and grow your social media at the best price.</p>',
        'reading_time_min' => 4,
    ],
];

foreach ($articles as $a) {
    $slug = $a['slug'];
    $exists = $db->fetch("SELECT id FROM blog_articles WHERE slug = ?", [$slug]);
    if ($exists) {
        echo "Skip (exists): $slug\n";
        continue;
    }
    $catId = $getCatId($a['category']);
    $articleId = $db->insert(
        "INSERT INTO blog_articles (category_id, slug, title, meta_description, meta_keywords, excerpt, body, status, published_at, reading_time_min) VALUES (?, ?, ?, ?, ?, ?, ?, 'published', ?, ?)",
        [
            $catId,
            $slug,
            $a['title'],
            $a['meta_description'],
            $a['meta_keywords'] ?? '',
            $a['excerpt'],
            $a['body'],
            $now,
            $a['reading_time_min'] ?? 3,
        ]
    );
    foreach ($getTagIds($a['tags']) as $tagId) {
        $db->execute("INSERT IGNORE INTO blog_article_tags (article_id, tag_id) VALUES (?, ?)", [$articleId, $tagId]);
    }
    echo "Added: $slug\n";
}

echo "Seed done. " . count($articles) . " articles.\n";
