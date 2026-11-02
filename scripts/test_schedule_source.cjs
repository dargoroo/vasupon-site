const {readFileSync} = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const code = readFileSync('assets/js/schedule-source.js', 'utf8');
const course = {day:'อังคาร', time:'08:30 - 12:30', course_code:'9061021', course_name:'โครงสร้างข้อมูลและอัลกอริทึม'};
const data = date => ({meta:{updated_at:date}, courses:[{...course}]});
const local = data('2026-05-29T11:30:00+07:00');
const fresh = data('2026-11-02T21:30:00+07:00');
async function load(replies) {
    const context = {window:{}, AbortController, setTimeout, clearTimeout, fetch:async url=>{
        const reply = replies[url.split('?')[0]];
        if (reply instanceof Error) throw reply;
        return {ok:reply !== null, json:async()=>reply};
    }};
    vm.runInNewContext(code, context);
    return context.window.RbruSchedule.load(['local','github']);
}
(async()=>{
    assert.deepEqual(await load({local, github:fresh}), fresh);
    assert.deepEqual(await load({local:fresh, github:local}), fresh);
    assert.deepEqual(await load({local, github:new Error('offline')}), local);
    assert.deepEqual(await load({local:null, github:fresh}), fresh);
    assert.deepEqual(await load({local, github:{courses:[{...course,course_name:', 1'}]}}), local);
    assert.deepEqual(await load({local, github:data('invalid date')}), local);
    assert.deepEqual(await load({local:{courses:[course]}, github:{courses:[course]}}), {courses:[course]});
    await assert.rejects(load({local:null, github:{courses:[]}}), /either source/);
    console.log('PASS: 8 schedule source cases (freshness, fallback, invalid data and total failure).');
})().catch(error=>{console.error(error);process.exitCode=1;});
