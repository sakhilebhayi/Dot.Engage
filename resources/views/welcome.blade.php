<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>dot.engage — Smart Contract & Communication Platform</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&display=swap" rel="stylesheet" />

        <!-- Styles / Scripts -->
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
    </head>
    <body style="font-family: 'Inter', sans-serif; background-color: #ffffff; color: #1B2878; margin: 0; padding: 0;">

        <!-- ─── Navigation ─────────────────────────────────────────────── -->
        <nav style="position: fixed; top: 0; left: 0; right: 0; z-index: 50; background: rgba(255,255,255,0.97); backdrop-filter: blur(8px); border-bottom: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
            <div style="max-width: 1200px; margin: 0 auto; padding: 0 24px; display: flex; align-items: center; justify-content: space-between; height: 68px;">
                <a href="/" style="display: flex; align-items: center; text-decoration: none;">
                    <img src="{{ asset('images/dot_engage.png') }}" alt="dot.engage" style="height: 40px; width: auto;">
                </a>

                @if (Route::has('login'))
                    <div style="display: flex; align-items: center; gap: 12px;">
                        @auth
                            <a href="{{ url('/dashboard') }}" style="padding: 9px 22px; background-color: #1B2878; color: #ffffff; font-size: 14px; font-weight: 600; border-radius: 10px; text-decoration: none; transition: background 0.15s;">
                                Dashboard
                            </a>
                        @else
                            <a href="{{ route('login') }}" style="padding: 9px 18px; color: #1B2878; font-size: 14px; font-weight: 500; text-decoration: none;">
                                Log in
                            </a>
                            @if (Route::has('register'))
                                <a href="{{ route('register') }}" style="padding: 9px 22px; background-color: #F5C200; color: #1B2878; font-size: 14px; font-weight: 700; border-radius: 10px; text-decoration: none; box-shadow: 0 2px 6px rgba(245,194,0,0.35);">
                                    Get Started
                                </a>
                            @endif
                        @endauth
                    </div>
                @endif
            </div>
        </nav>

        <!-- ─── Hero ────────────────────────────────────────────────────── -->
        <section style="padding-top: 110px; padding-bottom: 90px; color: #ffffff; overflow: hidden; position: relative;">
            <!-- Photographic Background: real man-signing-a-document-with-a-pen photo by Jakub Żerdzicki (@jakubzerdzicki), unsplash.com/photos/man-signing-a-document-with-a-pen-QI6NLgN5XnM -->
            <div style="position: absolute; inset: 0; background-image: url('https://images.unsplash.com/photo-1763729805496-b5dbf7f00c79?q=80&w=2400&auto=format&fit=crop'); background-size: cover; background-position: center;"></div>
            <div style="position: absolute; inset: 0; background: linear-gradient(135deg, rgba(27,40,120,0.94) 0%, rgba(30,55,153,0.9) 50%, rgba(27,40,120,0.88) 100%);"></div>
            <div style="position: absolute; inset: 0; background: linear-gradient(90deg, rgba(27,40,120,0.55) 0%, rgba(27,40,120,0.2) 55%, rgba(27,40,120,0.05) 100%);"></div>
            <!-- Decorative blobs -->
            <div style="position: absolute; top: -80px; right: -80px; width: 420px; height: 420px; background: rgba(245,194,0,0.08); border-radius: 50%; filter: blur(60px); pointer-events: none;"></div>
            <div style="position: absolute; bottom: -80px; left: -80px; width: 420px; height: 420px; background: rgba(245,194,0,0.06); border-radius: 50%; filter: blur(60px); pointer-events: none;"></div>

            <div style="max-width: 1200px; margin: 0 auto; padding: 0 24px; position: relative;">
                <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 56px; justify-content: space-between;">

                    <!-- Left: copy -->
                    <div style="flex: 1; min-width: 280px;">
                        <div style="display: inline-flex; align-items: center; gap: 8px; background: rgba(245,194,0,0.15); border: 1px solid rgba(245,194,0,0.3); border-radius: 999px; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #F5C200; margin-bottom: 24px;">
                            <span style="width: 8px; height: 8px; background: #F5C200; border-radius: 50%; animation: pulse 2s infinite;"></span>
                            Smart Business Platform
                        </div>

                        <h1 style="font-size: clamp(2.2rem, 5vw, 3.6rem); font-weight: 800; line-height: 1.15; margin: 0 0 20px 0; letter-spacing: -0.02em;">
                            Contracts.<br>
                            <span style="color: #F5C200;">Chat. Sign.</span><br>
                            Done.
                        </h1>

                        <p style="font-size: 1.1rem; color: #bfcae8; line-height: 1.7; max-width: 480px; margin: 0 0 36px 0;">
                            dot.engage brings contract management, real-time messaging, and video-call document signing into one seamless platform.
                        </p>

                        <div style="display: flex; flex-wrap: wrap; gap: 14px;">
                            @if (Route::has('register'))
                                <a href="{{ route('register') }}" style="padding: 14px 32px; background-color: #F5C200; color: #1B2878; font-size: 15px; font-weight: 700; border-radius: 12px; text-decoration: none; box-shadow: 0 4px 16px rgba(245,194,0,0.4);">
                                    Start for Free &rarr;
                                </a>
                            @endif
                            @if (Route::has('login'))
                                <a href="{{ route('login') }}" style="padding: 14px 32px; background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); color: #ffffff; font-size: 15px; font-weight: 500; border-radius: 12px; text-decoration: none;">
                                    Sign In
                                </a>
                            @endif
                        </div>
                    </div>

                    <!-- Right: logo graphic -->
                    <div style="flex-shrink: 0;">
                        <div style="background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1); border-radius: 28px; padding: 40px; backdrop-filter: blur(8px);">
                            <img src="{{ asset('images/dot_engage.png') }}" alt="dot.engage" style="width: 260px; max-width: 100%; height: auto; filter: drop-shadow(0 8px 32px rgba(0,0,0,0.3));">
                        </div>
                    </div>

                </div>
            </div>
        </section>

        <!-- ─── Stats strip ─────────────────────────────────────────────── -->
        <section style="background: #F5C200; padding: 20px 24px;">
            <div style="max-width: 1200px; margin: 0 auto; display: flex; flex-wrap: wrap; justify-content: center; gap: 40px;">
                <div style="text-align: center;">
                    <div style="font-size: 1.6rem; font-weight: 800; color: #1B2878;">100%</div>
                    <div style="font-size: 13px; font-weight: 600; color: #1B2878; opacity: 0.75;">Paperless Signing</div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 1.6rem; font-weight: 800; color: #1B2878;">Real-time</div>
                    <div style="font-size: 13px; font-weight: 600; color: #1B2878; opacity: 0.75;">Messaging</div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 1.6rem; font-weight: 800; color: #1B2878;">One Platform</div>
                    <div style="font-size: 13px; font-weight: 600; color: #1B2878; opacity: 0.75;">End-to-End</div>
                </div>
            </div>
        </section>

        <!-- ─── Features ─────────────────────────────────────────────────── -->
        <section style="padding: 90px 24px; background: #f8fafc;">
            <div style="max-width: 1200px; margin: 0 auto;">
                <div style="text-align: center; margin-bottom: 56px;">
                    <h2 style="font-size: clamp(1.7rem, 3vw, 2.4rem); font-weight: 800; color: #1B2878; margin: 0 0 12px 0;">Everything you need to close deals faster</h2>
                    <p style="color: #64748b; font-size: 1.05rem; max-width: 520px; margin: 0 auto; line-height: 1.6;">One unified workspace for your entire contract lifecycle — from creation to signed delivery.</p>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 28px;">

                    <!-- Card 1: Contracts -->
                    <div style="background: #ffffff; border-radius: 20px; padding: 36px 32px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.04); transition: box-shadow 0.2s;">
                        <div style="width: 52px; height: 52px; background: rgba(245,194,0,0.15); border-radius: 14px; display: flex; align-items: center; justify-content: center; margin-bottom: 20px;">
                            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#F5C200" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                <polyline points="14 2 14 8 20 8"/>
                                <line x1="16" y1="13" x2="8" y2="13"/>
                                <line x1="16" y1="17" x2="8" y2="17"/>
                                <polyline points="10 9 9 9 8 9"/>
                            </svg>
                        </div>
                        <h3 style="font-size: 1.15rem; font-weight: 700; color: #1B2878; margin: 0 0 10px 0;">Smart Contracts</h3>
                        <p style="color: #64748b; font-size: 0.93rem; line-height: 1.7; margin: 0;">Upload, share, and track contracts with full version history. Send to clients in seconds with a secure link.</p>
                    </div>

                    <!-- Card 2: Chat -->
                    <div style="background: #ffffff; border-radius: 20px; padding: 36px 32px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
                        <div style="width: 52px; height: 52px; background: rgba(27,40,120,0.08); border-radius: 14px; display: flex; align-items: center; justify-content: center; margin-bottom: 20px;">
                            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#1B2878" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                            </svg>
                        </div>
                        <h3 style="font-size: 1.15rem; font-weight: 700; color: #1B2878; margin: 0 0 10px 0;">Real-time Messaging</h3>
                        <p style="color: #64748b; font-size: 0.93rem; line-height: 1.7; margin: 0;">Stay connected with clients through secure, instant conversations with file attachments — all in one place.</p>
                    </div>

                    <!-- Card 3: Video Signing -->
                    <div style="background: #ffffff; border-radius: 20px; padding: 36px 32px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
                        <div style="width: 52px; height: 52px; background: rgba(245,194,0,0.15); border-radius: 14px; display: flex; align-items: center; justify-content: center; margin-bottom: 20px;">
                            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#F5C200" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polygon points="23 7 16 12 23 17 23 7"/>
                                <rect x="1" y="5" width="15" height="14" rx="2" ry="2"/>
                            </svg>
                        </div>
                        <h3 style="font-size: 1.15rem; font-weight: 700; color: #1B2878; margin: 0 0 10px 0;">Video-Call Signing</h3>
                        <p style="color: #64748b; font-size: 0.93rem; line-height: 1.7; margin: 0;">Request and capture document signatures during live video sessions — no printing, no delays.</p>
                    </div>

                </div>
            </div>
        </section>

        <!-- ─── How It Works ─────────────────────────────────────────────── -->
        <section style="padding: 90px 24px; background: #ffffff;">
            <div style="max-width: 900px; margin: 0 auto;">
                <div style="text-align: center; margin-bottom: 56px;">
                    <h2 style="font-size: clamp(1.7rem, 3vw, 2.4rem); font-weight: 800; color: #1B2878; margin: 0 0 12px 0;">How dot.engage works</h2>
                    <p style="color: #64748b; font-size: 1.05rem; max-width: 480px; margin: 0 auto; line-height: 1.6;">Three simple steps from upload to signed contract.</p>
                </div>

                <div style="display: flex; flex-wrap: wrap; gap: 0; position: relative;">

                    <!-- Step 1 -->
                    <div style="flex: 1; min-width: 220px; text-align: center; padding: 24px 20px;">
                        <div style="width: 56px; height: 56px; background: #1B2878; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 1.4rem; font-weight: 800; color: #F5C200;">1</div>
                        <h4 style="font-size: 1rem; font-weight: 700; color: #1B2878; margin: 0 0 8px 0;">Upload Your Contract</h4>
                        <p style="color: #64748b; font-size: 0.88rem; line-height: 1.6; margin: 0;">Drag and drop your PDF or document into the platform.</p>
                    </div>

                    <!-- Arrow -->
                    <div style="display: flex; align-items: center; padding: 0 4px; color: #F5C200; font-size: 1.5rem; font-weight: 900;">›</div>

                    <!-- Step 2 -->
                    <div style="flex: 1; min-width: 220px; text-align: center; padding: 24px 20px;">
                        <div style="width: 56px; height: 56px; background: #F5C200; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 1.4rem; font-weight: 800; color: #1B2878;">2</div>
                        <h4 style="font-size: 1rem; font-weight: 700; color: #1B2878; margin: 0 0 8px 0;">Share &amp; Discuss</h4>
                        <p style="color: #64748b; font-size: 0.88rem; line-height: 1.6; margin: 0;">Send to clients and collaborate via real-time chat.</p>
                    </div>

                    <!-- Arrow -->
                    <div style="display: flex; align-items: center; padding: 0 4px; color: #F5C200; font-size: 1.5rem; font-weight: 900;">›</div>

                    <!-- Step 3 -->
                    <div style="flex: 1; min-width: 220px; text-align: center; padding: 24px 20px;">
                        <div style="width: 56px; height: 56px; background: #1B2878; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 1.4rem; font-weight: 800; color: #F5C200;">3</div>
                        <h4 style="font-size: 1rem; font-weight: 700; color: #1B2878; margin: 0 0 8px 0;">Sign on Video Call</h4>
                        <p style="color: #64748b; font-size: 0.88rem; line-height: 1.6; margin: 0;">Collect legally-binding signatures live during your call.</p>
                    </div>

                </div>
            </div>
        </section>

        <!-- ─── CTA Banner ────────────────────────────────────────────────── -->
        <section style="padding: 80px 24px; position: relative; overflow: hidden;">
            <!-- Photographic Background: real woman-waving-during-a-video-call-on-her-laptop photo by Vitaly Gariev (@silverkblack), unsplash.com/photos/woman-waving-during-a-video-call-on-her-laptop-nSj0hdQUrW0 -->
            <div style="position: absolute; inset: 0; background-image: url('https://images.unsplash.com/photo-1752650733337-cb0189176fb9?q=80&w=2400&auto=format&fit=crop'); background-size: cover; background-position: center;"></div>
            <div style="position: absolute; inset: 0; background: linear-gradient(135deg, rgba(27,40,120,0.92), rgba(30,55,153,0.9));"></div>
            <div style="max-width: 680px; margin: 0 auto; text-align: center; position: relative;">
                <h2 style="font-size: clamp(1.8rem, 3.5vw, 2.6rem); font-weight: 800; color: #ffffff; margin: 0 0 16px 0;">Ready to engage smarter?</h2>
                <p style="color: #bfcae8; font-size: 1.05rem; line-height: 1.6; margin: 0 0 36px 0;">Join businesses that close deals faster and more securely with dot.engage.</p>
                @if (Route::has('register'))
                    <a href="{{ route('register') }}" style="display: inline-block; padding: 16px 44px; background-color: #F5C200; color: #1B2878; font-size: 15px; font-weight: 700; border-radius: 12px; text-decoration: none; box-shadow: 0 4px 20px rgba(245,194,0,0.45);">
                        Create Your Free Account &rarr;
                    </a>
                @endif
            </div>
        </section>

        <!-- ─── Footer ────────────────────────────────────────────────────── -->
        <footer style="background: #ffffff; border-top: 1px solid #e2e8f0; padding: 32px 24px;">
            <div style="max-width: 1200px; margin: 0 auto; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px;">
                <img src="{{ asset('images/dot_engage.png') }}" alt="dot.engage" style="height: 32px; width: auto;">
                <p style="font-size: 13px; color: #94a3b8; margin: 0;">&copy; {{ date('Y') }} dot.engage &middot; All rights reserved.</p>
            </div>
        </footer>

        <style>
            @keyframes pulse {
                0%, 100% { opacity: 1; }
                50% { opacity: 0.4; }
            }
            a { transition: opacity 0.15s ease; }
        </style>

    </body>
</html>
