// Prefer the newest valid schedule, keeping the university copy as an offline fallback.
(function (root) {
    "use strict";
    function validSchedule(data) {
        return data && Array.isArray(data.courses) && data.courses.length > 0 &&
            data.courses.every(course => course &&
                /[A-Za-zก-๙]/.test(course.course_name || "") &&
                /^\d{6,8}(?:-\d{2})?$/.test(course.course_code || "") &&
                /^\d{2}:\d{2}\s*-\s*\d{2}:\d{2}$/.test(course.time || ""));
    }
    async function load(urls) {
        const results = await Promise.allSettled(urls.map(async url => {
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 8000);
            try {
                const response = await fetch(url + (url.includes("?") ? "&" : "?") + "t=" + Date.now(), {
                    cache: "no-store", signal: controller.signal
                });
                if (!response.ok) throw new Error("Schedule source unavailable");
                const data = await response.json();
                if (!validSchedule(data)) throw new Error("Incomplete schedule data");
                return data;
            } finally {
                clearTimeout(timeout);
            }
        }));
        const candidates = results.filter(result => result.status === "fulfilled").map(result => result.value);
        if (!candidates.length) throw new Error("Schedule could not be loaded from either source.");
        return candidates.reduce((chosen, next) => {
            const time = data => Date.parse(data.meta && data.meta.updated_at) || 0;
            return time(next) > time(chosen) ? next : chosen;
        });
    }
    root.RbruSchedule = { load };
})(window);
