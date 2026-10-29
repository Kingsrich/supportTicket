<!-- MAIN CONTENT -->
<div class="flex-1 ml-72">

    <!-- TOP NAVBAR -->
    <header class="bg-white shadow-sm px-10 py-5 flex items-center justify-between sticky top-0 z-40">

        <!-- LEFT -->
        <div>

            <h2 class="text-3xl font-extrabold text-gray-800">
                <?= isset($page_heading) ? $page_heading : 'Dashboard'; ?>
            </h2>

            <p class="text-gray-500 mt-1">
                Welcome back,
                <?= $_SESSION['fullname']; ?>
            </p>

        </div>

        <!-- RIGHT -->
        <div class="flex items-center gap-6">

            <!-- SEARCH -->
            <div class="hidden lg:flex items-center bg-gray-100 rounded-2xl px-4 py-3 w-80">

                <i class="fas fa-search text-gray-400 mr-3"></i>

                <input type="text" placeholder="Search..." class="bg-transparent outline-none w-full text-sm">

            </div>

            <!-- NOTIFICATIONS -->
            <button class="relative bg-gray-100 hover:bg-gray-200 transition w-12 h-12 rounded-2xl">

                <i class="fas fa-bell text-gray-600"></i>

                <span class="absolute top-2 right-2 w-3 h-3 bg-red-500 rounded-full"></span>

            </button>

            <!-- USER -->
            <div class="flex items-center gap-4">

                <div class="text-right hidden md:block">

                    <h4 class="font-bold text-gray-800">
                        <?= $_SESSION['fullname']; ?>
                    </h4>

                    <p class="text-sm text-gray-500 capitalize">
                        <?= $_SESSION['role']; ?>
                    </p>

                </div>

                <div
                    class="w-14 h-14 rounded-full bg-indigo-600 text-white flex items-center justify-center text-xl font-bold">

                    <?= strtoupper(substr($_SESSION['fullname'], 0, 1)); ?>

                </div>

            </div>

        </div>

    </header>

    <!-- PAGE CONTENT -->
    <main class="p-10">