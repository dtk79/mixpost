<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Peachy Posting</title>
    <link rel="shortcut icon" href="{{ asset('/vendor/mixpost/favicon.ico') }}">
    <style>
        :root {
            color: #24222a;
            background: #fff8f7;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 32px 18px;
            background:
                radial-gradient(circle at top left, rgb(255 84 194 / 18%), transparent 34%),
                radial-gradient(circle at bottom right, rgb(255 159 28 / 22%), transparent 34%),
                #fff8f7;
        }

        main {
            width: calc(100vw - 36px);
            max-width: 920px;
            min-width: 0;
            display: grid;
            gap: 28px;
            grid-template-columns: 0.9fr 1.1fr;
            align-items: center;
        }

        .brand {
            text-align: center;
        }

        .brand img {
            width: min(100%, 360px);
            border-radius: 28px;
            box-shadow: 0 24px 60px rgb(244 91 88 / 22%);
        }

        .content {
            min-width: 0;
            background: rgb(255 255 255 / 82%);
            border: 1px solid rgb(255 105 82 / 18%);
            border-radius: 8px;
            padding: clamp(24px, 5vw, 44px);
            box-shadow: 0 18px 48px rgb(63 48 44 / 10%);
        }

        h1 {
            margin: 0;
            max-width: 10ch;
            font-size: clamp(44px, 7vw, 76px);
            line-height: 0.92;
            letter-spacing: 0;
        }

        p {
            margin: 20px 0 0;
            width: 100%;
            max-width: 34rem;
            color: #5d5660;
            font-size: 18px;
            line-height: 1.6;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 28px;
        }

        a,
        button {
            min-height: 48px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 15px;
        }

        .login {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 20px;
            color: white;
            background: #ff5a4f;
            text-decoration: none;
            box-shadow: 0 12px 24px rgb(255 90 79 / 22%);
        }

        form {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 16px;
        }

        input {
            flex: 1 1 220px;
            min-width: 0;
            min-height: 48px;
            border: 1px solid #ead9d5;
            border-radius: 8px;
            padding: 0 14px;
            font: inherit;
            background: #fff;
        }

        button {
            border: 0;
            padding: 0 18px;
            color: #24222a;
            background: #ffd2c4;
        }

        footer {
            margin-top: 28px;
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            color: #796f77;
            font-size: 14px;
        }

        footer a {
            min-height: 0;
            color: inherit;
            font-size: inherit;
            font-weight: 600;
        }

        footer > * {
            max-width: 100%;
        }

        @media (max-width: 760px) {
            main {
                grid-template-columns: 1fr;
            }

            .brand img {
                width: min(72vw, 260px);
            }
        }

        @media (max-width: 520px) {
            main {
                width: calc(100vw - 48px);
                max-width: 330px;
            }

            .content {
                padding: 24px;
            }

            form {
                display: grid;
            }

            input,
            button {
                width: 100%;
            }

            footer {
                display: grid;
                gap: 10px;
            }
        }
    </style>
</head>
<body>
<main>
    <div class="brand">
        <img src="{{ asset('/vendor/mixpost/peachy-posting.png') }}" alt="Peachy Posting">
    </div>

    <section class="content" aria-labelledby="title">
        <h1 id="title">Peachy Posting</h1>
        <p>Manage social posting for Peachy accounts. Log in if you already have access, or request an account with your work email.</p>

        <div class="actions">
            <a class="login" href="https://mixpost.peachyhq.com/mixpost/login">Log in</a>
        </div>

        {{-- ponytail: mailto form, replace with a POST endpoint when account requests need tracking. --}}
        <form action="mailto:dan@peachyhq.com?subject=Peachy%20Posting%20account%20request" method="post" enctype="text/plain">
            <input type="email" name="email" autocomplete="email" placeholder="you@peachyhq.com" required>
            <button type="submit">Request account</button>
        </form>

        <footer>
            <a href="https://mixpost.peachyhq.com/pages/terms">Terms of Service</a>
            <a href="https://mixpost.peachyhq.com/pages/privacy">Privacy Policy</a>
            <span>&copy; {{ date('Y') }} Peachy Posting</span>
        </footer>
    </section>
</main>
</body>
</html>
